<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Integration;

use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Schema;
use IndianOpinion\TranslateSidecar\Storage\WpdbStore;
use IndianOpinion\TranslateSidecar\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Runs the database store against a real MySQL or MariaDB. Skipped unless
 * TS_MYSQL_SOCKET or TS_MYSQL_HOST is set; the database named by
 * TS_MYSQL_DB (default translate_sidecar_test) is emptied first.
 */
final class WpdbStoreTest extends TestCase
{
    private MysqliWpdb $wpdb;
    private Schema $schema;
    private WpdbStore $store;

    protected function setUp(): void
    {
        $socket = (string) getenv('TS_MYSQL_SOCKET');
        $host = (string) getenv('TS_MYSQL_HOST');
        if ($socket === '' && $host === '') {
            self::markTestSkipped('Set TS_MYSQL_SOCKET or TS_MYSQL_HOST to run database tests.');
        }
        \WpStub::reset();
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new \mysqli($host !== '' ? $host : 'localhost', (string) (getenv('TS_MYSQL_USER') ?: 'root'), (string) getenv('TS_MYSQL_PASSWORD'), '', (int) (getenv('TS_MYSQL_PORT') ?: 3306), $socket !== '' ? $socket : null);
        $name = (string) (getenv('TS_MYSQL_DB') ?: 'translate_sidecar_test');
        $db->query('DROP DATABASE IF EXISTS `' . $name . '`');
        $db->query('CREATE DATABASE `' . $name . '`');
        $db->select_db($name);
        $db->set_charset('utf8mb4');
        $this->wpdb = new MysqliWpdb($db);
        foreach ((new Schema($this->wpdb))->sql() as $sql) {
            $db->query($sql);
        }
        $this->schema = new Schema($this->wpdb);
        self::assertTrue($this->schema->addSlot(), 'Generated column and unique key are supported.');
        self::assertSame([], $this->schema->verify());
        $this->store = new WpdbStore($this->wpdb, $this->schema);
    }

    private function row(string $state, int $post = 1): Row
    {
        $r = new Row();
        $r->postId = $post;
        $r->lang = 'fr';
        $r->state = $state;
        $r->title = 'Titre';
        $r->segments = [['id' => 's1', 'hash' => str_repeat('a', 64), 'text' => 'Texte']];
        $r->createdGmt = '2026-01-01 00:00:00';
        $r->updatedGmt = '2026-01-01 00:00:00';
        $r->approvedGmt = '2026-01-02 00:00:00';

        return $r;
    }

    public function testRowsRoundTripAndConditionalUpdates(): void
    {
        $id = $this->store->insert($this->row(Row::CANDIDATE));
        $row = $this->store->find($id);
        self::assertSame('Titre', $row?->title);
        self::assertSame('Texte', $row?->segments[0]['text']);
        self::assertTrue($this->store->update($id, ['state' => Row::LIVE], Row::CANDIDATE));
        self::assertFalse($this->store->update($id, ['state' => Row::LIVE], Row::CANDIDATE), 'Second approver loses.');
        self::assertCount(1, $this->store->liveForPost(1));
        self::assertSame([1], array_keys($this->store->liveForPosts([1, 2], 'fr')));
        self::assertSame(1, $this->store->countLiveForLang('fr'));
        self::assertSame(['fr' => ['live' => 1]], $this->store->stateCounts());
    }

    public function testUniqueSlotRefusesASecondLiveRow(): void
    {
        $this->store->insert($this->row(Row::LIVE));
        $this->store->insert($this->row(Row::PRIOR));
        $this->store->insert($this->row(Row::PRIOR));
        $this->expectException(\RuntimeException::class);
        $this->store->insert($this->row(Row::LIVE));
    }

    public function testCountersMemoryAndLocks(): void
    {
        self::assertTrue($this->store->counterTryAdd('2026-01-01', 'calls', 1, 2));
        self::assertTrue($this->store->counterTryAdd('2026-01-01', 'calls', 1, 2));
        self::assertFalse($this->store->counterTryAdd('2026-01-01', 'calls', 1, 2));
        self::assertSame(2, $this->store->counterGet('2026-01-01', 'calls'));
        $this->store->counterAdd('2026-01-01', 'chars', 50);
        $this->store->counterAdd('2026-01-01', 'chars', -20);
        self::assertSame(30, $this->store->counterGet('2026-01-01', 'chars'));

        $this->store->memoryPut('fr', ['h1' => 'un', 'h2' => 'deux'], 7, '2026-01-01 00:00:00');
        $this->store->memoryPut('fr', ['h1' => 'uno'], 8, '2026-02-01 00:00:00');
        self::assertSame(['h1' => 'uno', 'h2' => 'deux'], $this->store->memoryGet('fr', ['h1', 'h2', 'h3']));
        self::assertSame(1, $this->store->memoryPrune('2026-01-15 00:00:00'));
        self::assertSame(1, $this->store->memoryDeleteForRows([8]));

        self::assertTrue($this->store->lock('tick', 60));
        self::assertFalse($this->store->lock('tick', 60));
        $this->store->unlock('tick');
        self::assertTrue($this->store->lock('tick', -1));
        self::assertTrue($this->store->lock('tick', 60), 'An expired lock is taken over.');
    }

    public function testJobsAndPruning(): void
    {
        $q = $this->row(Row::QUEUED);
        $q->nextAttemptGmt = '2026-01-01 00:00:00';
        $id = $this->store->insert($q);
        self::assertSame($id, $this->store->activeJob(1, 'fr')?->id);
        self::assertCount(1, $this->store->dueJobs('2026-01-01 00:00:01', 5));
        self::assertCount(0, $this->store->dueJobs('2025-12-31 00:00:00', 5));
        $this->store->update($id, ['state' => Row::FAILED, 'updated_gmt' => '2026-01-01 00:00:00']);
        self::assertCount(1, $this->store->recentFailures(20));
        self::assertSame(1, $this->store->prune([Row::FAILED], '2026-02-01 00:00:00'));
        $this->store->insert($this->row(Row::LIVE, 3));
        self::assertCount(1, $this->store->liveForLang('fr', 0, 10));
        self::assertSame(1, $this->store->deleteForLang('fr'));
    }
}
