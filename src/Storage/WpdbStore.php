<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Storage;

/**
 * The database store, on top of $wpdb. Every query is prepared.
 */
final class WpdbStore implements Store
{
    private int $queries = 0;
    private string $rows;
    private string $memory;
    private string $counters;

    /**
     * @param \wpdb $wpdb the database object, or anything with its methods
     */
    public function __construct(private readonly object $wpdb, private readonly Schema $schema)
    {
        $t = $schema->tables();
        $this->rows = $t['rows'];
        $this->memory = $t['memory'];
        $this->counters = $t['counters'];
    }

    public function isWritable(): bool
    {
        return $this->schema->isVerified();
    }

    private function guardWrite(): void
    {
        if (!$this->isWritable()) {
            throw new SchemaException('The translations schema is not verified; writes are blocked. Run "wp translate-sidecar verify-schema".');
        }
    }

    private function fail(string $what): never
    {
        throw new \RuntimeException(sprintf('Database error while %s: %s', $what, (string) $this->wpdb->last_error));
    }

    /**
     * @param list<mixed> $args
     */
    private function prepare(string $sql, array $args): string
    {
        return $args === [] ? $sql : (string) $this->wpdb->prepare($sql, ...$args);
    }

    /**
     * @param list<mixed> $args
     * @return list<Row>
     */
    private function rowsQuery(string $sql, array $args): array
    {
        $this->queries++;
        $result = $this->wpdb->get_results($this->prepare($sql, $args), ARRAY_A);
        if (!is_array($result)) {
            $this->fail('reading rows');
        }

        return array_values(array_map(static fn ($r): Row => Row::fromDb((array) $r), $result));
    }

    public function insert(Row $row): int
    {
        $this->guardWrite();
        $this->queries++;
        $ok = $this->wpdb->insert($this->rows, $row->toDb());
        if ($ok === false) {
            $this->fail('inserting a row');
        }

        return (int) $this->wpdb->insert_id;
    }

    public function find(int $id): ?Row
    {
        $rows = $this->rowsQuery("SELECT * FROM {$this->rows} WHERE id = %d", [$id]);

        return $rows[0] ?? null;
    }

    public function rowsFor(int $postId, string $lang, ?array $states = null): array
    {
        $sql = "SELECT * FROM {$this->rows} WHERE post_id = %d AND lang = %s";
        $args = [$postId, $lang];
        if ($states !== null && $states !== []) {
            $sql .= ' AND state IN (' . implode(',', array_fill(0, count($states), '%s')) . ')';
            $args = array_merge($args, $states);
        }

        return $this->rowsQuery($sql . ' ORDER BY id DESC', $args);
    }

    public function liveForPost(int $postId): array
    {
        return $this->rowsQuery("SELECT * FROM {$this->rows} WHERE post_id = %d AND state = 'live'", [$postId]);
    }

    public function liveForPosts(array $postIds, string $lang): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if ($postIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($postIds), '%d'));
        $rows = $this->rowsQuery(
            "SELECT * FROM {$this->rows} WHERE post_id IN ({$in}) AND lang = %s AND state = 'live'",
            array_merge($postIds, [$lang])
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row->postId] = $row;
        }

        return $out;
    }

    public function liveNarrowForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if ($postIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($postIds), '%d'));

        return $this->rowsQuery(
            "SELECT id, post_id, lang, state, source_hash, approved_gmt, updated_gmt, approved_by FROM {$this->rows}
             WHERE post_id IN ({$in}) AND state = 'live'",
            $postIds
        );
    }

    public function activeJob(int $postId, string $lang): ?Row
    {
        $rows = $this->rowsQuery(
            "SELECT * FROM {$this->rows} WHERE post_id = %d AND lang = %s AND state IN ('queued','running') ORDER BY id ASC LIMIT 1",
            [$postId, $lang]
        );

        return $rows[0] ?? null;
    }

    public function dueJobs(string $nowGmt, int $limit, ?string $lang = null): array
    {
        $sql = "SELECT * FROM {$this->rows} WHERE state = 'queued' AND (next_attempt_gmt IS NULL OR next_attempt_gmt <= %s)";
        $args = [$nowGmt];
        if ($lang !== null) {
            $sql .= ' AND lang = %s';
            $args[] = $lang;
        }
        $args[] = $limit;

        return $this->rowsQuery($sql . ' ORDER BY id ASC LIMIT %d', $args);
    }

    public function recoverStalled(string $beforeGmt, int $maxAttempts, string $nowGmt): int
    {
        $this->guardWrite();
        $this->queries += 2;
        $failed = $this->wpdb->query($this->prepare(
            "UPDATE {$this->rows} SET state = 'failed', reason = 'stalled', updated_gmt = %s
             WHERE state = 'running' AND updated_gmt < %s AND attempts >= %d",
            [$nowGmt, $beforeGmt, $maxAttempts]
        ));
        $requeued = $this->wpdb->query($this->prepare(
            "UPDATE {$this->rows} SET state = 'queued', reason = 'stalled', attempts = attempts + 1, next_attempt_gmt = %s, updated_gmt = %s
             WHERE state = 'running' AND updated_gmt < %s",
            [$nowGmt, $nowGmt, $beforeGmt]
        ));
        if ($failed === false || $requeued === false) {
            $this->fail('recovering stalled jobs');
        }

        return (int) $failed + (int) $requeued;
    }

    public function update(int $id, array $changes, ?string $expectedState = null): bool
    {
        $this->guardWrite();
        $this->queries++;
        foreach ($changes as $k => $v) {
            if (is_array($v)) {
                $changes[$k] = Row::encode($v);
            } elseif (is_bool($v)) {
                $changes[$k] = $v ? 1 : 0;
            }
        }
        $where = ['id' => $id];
        if ($expectedState !== null) {
            $where['state'] = $expectedState;
        }
        $result = $this->wpdb->update($this->rows, $changes, $where);
        if ($result === false) {
            $this->fail('updating a row');
        }

        return $expectedState === null ? true : (int) $result === 1;
    }

    public function deleteForPost(int $postId): array
    {
        $this->guardWrite();
        $this->queries++;
        $ids = $this->wpdb->get_col($this->prepare("SELECT id FROM {$this->rows} WHERE post_id = %d", [$postId]));
        $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));
        if ($ids !== []) {
            $this->queries++;
            $this->wpdb->query($this->prepare("DELETE FROM {$this->rows} WHERE post_id = %d", [$postId]));
        }

        return $ids;
    }

    public function deleteForLang(string $lang): int
    {
        $this->guardWrite();
        $this->queries++;
        $n = $this->wpdb->query($this->prepare("DELETE FROM {$this->rows} WHERE lang = %s", [$lang]));
        $this->queries++;
        $this->wpdb->query($this->prepare("DELETE FROM {$this->memory} WHERE lang = %s", [$lang]));

        return (int) $n;
    }

    public function liveForLang(string $lang, int $offset, int $limit): array
    {
        return $this->rowsQuery(
            "SELECT id, post_id, lang, state, source_hash, approved_gmt, updated_gmt, approved_by FROM {$this->rows}
             WHERE lang = %s AND state = 'live' ORDER BY approved_gmt DESC, id DESC LIMIT %d OFFSET %d",
            [$lang, $limit, $offset]
        );
    }

    public function countLiveForLang(string $lang): int
    {
        $this->queries++;

        return (int) $this->wpdb->get_var($this->prepare("SELECT COUNT(*) FROM {$this->rows} WHERE lang = %s AND state = 'live'", [$lang]));
    }

    public function stateCounts(): array
    {
        $this->queries++;
        $result = $this->wpdb->get_results("SELECT lang, state, COUNT(*) AS n FROM {$this->rows} GROUP BY lang, state", ARRAY_A);
        $out = [];
        foreach (is_array($result) ? $result : [] as $r) {
            $r = (array) $r;
            $out[(string) $r['lang']][(string) $r['state']] = (int) $r['n'];
        }

        return $out;
    }

    public function recentFailures(int $limit): array
    {
        return $this->rowsQuery(
            "SELECT id, post_id, lang, state, reason, adapter, attempts, updated_gmt, guard_report FROM {$this->rows}
             WHERE state = 'failed' ORDER BY updated_gmt DESC, id DESC LIMIT %d",
            [$limit]
        );
    }

    public function candidates(?string $lang, int $offset, int $limit): array
    {
        $sql = "SELECT * FROM {$this->rows} WHERE state = 'candidate'";
        $args = [];
        if ($lang !== null) {
            $sql .= ' AND lang = %s';
            $args[] = $lang;
        }
        $args[] = $limit;
        $args[] = $offset;

        return $this->rowsQuery($sql . ' ORDER BY created_gmt ASC, id ASC LIMIT %d OFFSET %d', $args);
    }

    public function prune(array $states, string $beforeGmt): int
    {
        $this->guardWrite();
        if ($states === []) {
            return 0;
        }
        $this->queries++;
        $in = implode(',', array_fill(0, count($states), '%s'));

        return (int) $this->wpdb->query($this->prepare(
            "DELETE FROM {$this->rows} WHERE state IN ({$in}) AND updated_gmt < %s",
            array_merge($states, [$beforeGmt])
        ));
    }

    public function memoryGet(string $lang, array $hashes): array
    {
        $hashes = array_values(array_unique($hashes));
        if ($hashes === []) {
            return [];
        }
        $this->queries++;
        $in = implode(',', array_fill(0, count($hashes), '%s'));
        $result = $this->wpdb->get_results($this->prepare(
            "SELECT segment_hash, text FROM {$this->memory} WHERE lang = %s AND segment_hash IN ({$in})",
            array_merge([$lang], $hashes)
        ), ARRAY_A);
        $out = [];
        foreach (is_array($result) ? $result : [] as $r) {
            $r = (array) $r;
            $out[(string) $r['segment_hash']] = (string) $r['text'];
        }

        return $out;
    }

    public function memoryPut(string $lang, array $entries, int $rowId, string $nowGmt): void
    {
        $this->guardWrite();
        foreach (array_chunk($entries, 100, true) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $hash => $text) {
                $values[] = '(%s, %s, %s, %d, %s)';
                array_push($args, (string) $hash, $lang, $text, $rowId, $nowGmt);
            }
            $this->queries++;
            $ok = $this->wpdb->query($this->prepare(
                "INSERT INTO {$this->memory} (segment_hash, lang, text, row_id, last_used_gmt) VALUES "
                . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE text = VALUES(text), row_id = VALUES(row_id), last_used_gmt = VALUES(last_used_gmt)',
                $args
            ));
            if ($ok === false) {
                $this->fail('writing translation memory');
            }
        }
    }

    public function memoryPrune(string $beforeGmt): int
    {
        $this->queries++;

        return (int) $this->wpdb->query($this->prepare("DELETE FROM {$this->memory} WHERE last_used_gmt < %s", [$beforeGmt]));
    }

    public function memoryDeleteForRows(array $rowIds): int
    {
        $rowIds = array_values(array_map('intval', $rowIds));
        if ($rowIds === []) {
            return 0;
        }
        $this->queries++;
        $in = implode(',', array_fill(0, count($rowIds), '%d'));

        return (int) $this->wpdb->query($this->prepare("DELETE FROM {$this->memory} WHERE row_id IN ({$in})", $rowIds));
    }

    public function counterTryAdd(string $day, string $metric, int $by, int $limit): bool
    {
        $this->guardWrite();
        $this->queries += 2;
        $this->wpdb->query($this->prepare("INSERT IGNORE INTO {$this->counters} (day, metric, n) VALUES (%s, %s, 0)", [$day, $metric]));
        $n = $this->wpdb->query($this->prepare(
            "UPDATE {$this->counters} SET n = n + %d WHERE day = %s AND metric = %s AND n + %d <= %d",
            [$by, $day, $metric, $by, $limit]
        ));
        if ($n === false) {
            $this->fail('updating a counter');
        }

        return (int) $n === 1;
    }

    public function counterAdd(string $day, string $metric, int $by): void
    {
        $this->guardWrite();
        $this->queries++;
        $ok = $this->wpdb->query($this->prepare(
            "INSERT INTO {$this->counters} (day, metric, n) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE n = n + VALUES(n)",
            [$day, $metric, $by]
        ));
        if ($ok === false) {
            $this->fail('updating a counter');
        }
    }

    public function counterGet(string $day, string $metric): int
    {
        $this->queries++;

        return (int) $this->wpdb->get_var($this->prepare("SELECT n FROM {$this->counters} WHERE day = %s AND metric = %s", [$day, $metric]));
    }

    public function counterPrune(string $beforeDay): int
    {
        $this->queries++;

        return (int) $this->wpdb->query($this->prepare("DELETE FROM {$this->counters} WHERE day < %s", [$beforeDay]));
    }

    /**
     * Named lock stored as a non-autoloaded option row. Taking it is an
     * INSERT IGNORE on the unique option name; an expired lock is taken over
     * with a compare-and-set update.
     */
    public function lock(string $name, int $ttl): bool
    {
        $option = 'translate_sidecar_lock_' . $name;
        $now = time();
        $table = (string) $this->wpdb->options;
        $this->queries++;
        $inserted = $this->wpdb->query($this->prepare(
            "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            [$option, (string) ($now + $ttl)]
        ));
        if ((int) $inserted === 1) {
            return true;
        }
        $this->queries++;
        $current = $this->wpdb->get_var($this->prepare("SELECT option_value FROM {$table} WHERE option_name = %s", [$option]));
        if ($current !== null && (int) $current > $now) {
            return false;
        }
        $this->queries++;
        $taken = $this->wpdb->query($this->prepare(
            "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            [(string) ($now + $ttl), $option, (string) $current]
        ));

        return (int) $taken === 1;
    }

    public function unlock(string $name): void
    {
        $this->queries++;
        $table = (string) $this->wpdb->options;
        $this->wpdb->query($this->prepare("DELETE FROM {$table} WHERE option_name = %s", ['translate_sidecar_lock_' . $name]));
    }

    public function queryCount(): int
    {
        return $this->queries;
    }
}
