<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Storage;

/**
 * In-memory store with the same contract as the database store. Used by
 * the tests and the examples; never by a live site.
 */
final class MemoryStore implements Store
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;
    /** @var array<string, array{text:string, row_id:int, last_used_gmt:string}> */
    private array $memory = [];
    /** @var array<string, int> */
    private array $counters = [];
    /** @var array<string, int> */
    private array $locks = [];
    private int $queries = 0;
    public bool $writable = true;
    public ?\Closure $clock = null;

    public function isWritable(): bool
    {
        return $this->writable;
    }

    private function guardWrite(): void
    {
        if (!$this->writable) {
            throw new SchemaException('The translations schema is not verified; writes are blocked.');
        }
    }

    public function insert(Row $row): int
    {
        $this->guardWrite();
        $this->queries++;
        $data = $row->toDb();
        $this->assertSlotFree($data, 0);
        $id = $this->nextId++;
        $data['id'] = $id;
        $this->rows[$id] = $data;

        return $id;
    }

    /**
     * Mirrors the unique key on (post_id, lang, slot).
     *
     * @param array<string, mixed> $data
     */
    private function assertSlotFree(array $data, int $selfId): void
    {
        if (!in_array($data['state'], [Row::LIVE, Row::CANDIDATE], true)) {
            return;
        }
        foreach ($this->rows as $id => $r) {
            if ($id !== $selfId && $r['post_id'] === $data['post_id'] && $r['lang'] === $data['lang'] && $r['state'] === $data['state']) {
                throw new \RuntimeException(sprintf('Duplicate %s row for post %d, language %s.', $data['state'], $data['post_id'], $data['lang']));
            }
        }
    }

    public function find(int $id): ?Row
    {
        $this->queries++;

        return isset($this->rows[$id]) ? Row::fromDb($this->rows[$id]) : null;
    }

    public function rowsFor(int $postId, string $lang, ?array $states = null): array
    {
        $this->queries++;

        return $this->select(static fn (array $r): bool => $r['post_id'] === $postId && $r['lang'] === $lang
            && ($states === null || in_array($r['state'], $states, true)), true);
    }

    public function liveForPost(int $postId): array
    {
        $this->queries++;

        return $this->select(static fn (array $r): bool => $r['post_id'] === $postId && $r['state'] === Row::LIVE, false);
    }

    public function liveForPosts(array $postIds, string $lang): array
    {
        $this->queries++;
        $out = [];
        foreach ($this->select(static fn (array $r): bool => in_array($r['post_id'], $postIds, true) && $r['lang'] === $lang && $r['state'] === Row::LIVE, false) as $row) {
            $out[$row->postId] = $row;
        }

        return $out;
    }

    public function activeJob(int $postId, string $lang): ?Row
    {
        $this->queries++;
        $rows = $this->select(static fn (array $r): bool => $r['post_id'] === $postId && $r['lang'] === $lang
            && in_array($r['state'], [Row::QUEUED, Row::RUNNING], true), false);

        return $rows[0] ?? null;
    }

    public function dueJobs(string $nowGmt, int $limit, ?string $lang = null): array
    {
        $this->queries++;
        $rows = $this->select(static fn (array $r): bool => $r['state'] === Row::QUEUED
            && ($r['next_attempt_gmt'] === null || $r['next_attempt_gmt'] <= $nowGmt)
            && ($lang === null || $r['lang'] === $lang), false);

        return array_slice($rows, 0, $limit);
    }

    public function update(int $id, array $changes, ?string $expectedState = null): bool
    {
        $this->guardWrite();
        $this->queries++;
        if (!isset($this->rows[$id])) {
            return false;
        }
        if ($expectedState !== null && $this->rows[$id]['state'] !== $expectedState) {
            return false;
        }
        $next = array_merge($this->rows[$id], $changes);
        $this->assertSlotFree($next, $id);
        $this->rows[$id] = $next;

        return true;
    }

    public function deleteForPost(int $postId): array
    {
        $this->guardWrite();
        $this->queries++;
        $ids = [];
        foreach ($this->rows as $id => $r) {
            if ($r['post_id'] === $postId) {
                $ids[] = $id;
                unset($this->rows[$id]);
            }
        }

        return $ids;
    }

    public function deleteForLang(string $lang): int
    {
        $this->guardWrite();
        $this->queries++;
        $n = 0;
        foreach ($this->rows as $id => $r) {
            if ($r['lang'] === $lang) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function liveForLang(string $lang, int $offset, int $limit): array
    {
        $this->queries++;
        $rows = $this->select(static fn (array $r): bool => $r['lang'] === $lang && $r['state'] === Row::LIVE, false);
        usort($rows, static fn (Row $a, Row $b): int => [(string) $b->approvedGmt, $b->id] <=> [(string) $a->approvedGmt, $a->id]);

        return array_slice($rows, $offset, $limit);
    }

    public function countLiveForLang(string $lang): int
    {
        $this->queries++;

        return count($this->select(static fn (array $r): bool => $r['lang'] === $lang && $r['state'] === Row::LIVE, false));
    }

    public function stateCounts(): array
    {
        $this->queries++;
        $out = [];
        foreach ($this->rows as $r) {
            $out[$r['lang']][$r['state']] = ($out[$r['lang']][$r['state']] ?? 0) + 1;
        }

        return $out;
    }

    public function recentFailures(int $limit): array
    {
        $this->queries++;
        $rows = $this->select(static fn (array $r): bool => $r['state'] === Row::FAILED, true);

        return array_slice($rows, 0, $limit);
    }

    public function candidates(?string $lang, int $offset, int $limit): array
    {
        $this->queries++;
        $rows = $this->select(static fn (array $r): bool => $r['state'] === Row::CANDIDATE && ($lang === null || $r['lang'] === $lang), false);

        return array_slice($rows, $offset, $limit);
    }

    public function prune(array $states, string $beforeGmt): int
    {
        $this->guardWrite();
        $this->queries++;
        $n = 0;
        foreach ($this->rows as $id => $r) {
            if (in_array($r['state'], $states, true) && (string) $r['updated_gmt'] < $beforeGmt) {
                unset($this->rows[$id]);
                $n++;
            }
        }

        return $n;
    }

    public function memoryGet(string $lang, array $hashes): array
    {
        $this->queries++;
        $out = [];
        foreach ($hashes as $hash) {
            $key = $hash . '|' . $lang;
            if (isset($this->memory[$key])) {
                $out[$hash] = $this->memory[$key]['text'];
            }
        }

        return $out;
    }

    public function memoryPut(string $lang, array $entries, int $rowId, string $nowGmt): void
    {
        $this->guardWrite();
        $this->queries++;
        foreach ($entries as $hash => $text) {
            $this->memory[$hash . '|' . $lang] = ['text' => $text, 'row_id' => $rowId, 'last_used_gmt' => $nowGmt];
        }
    }

    public function memoryPrune(string $beforeGmt): int
    {
        $this->queries++;
        $n = 0;
        foreach ($this->memory as $key => $m) {
            if ($m['last_used_gmt'] < $beforeGmt) {
                unset($this->memory[$key]);
                $n++;
            }
        }

        return $n;
    }

    public function memoryDeleteForRows(array $rowIds): int
    {
        $this->queries++;
        $n = 0;
        foreach ($this->memory as $key => $m) {
            if (in_array($m['row_id'], $rowIds, true)) {
                unset($this->memory[$key]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return int number of memory entries, for tests
     */
    public function memorySize(): int
    {
        return count($this->memory);
    }

    public function counterTryAdd(string $day, string $metric, int $by, int $limit): bool
    {
        $this->guardWrite();
        $this->queries++;
        $key = $day . '|' . $metric;
        $current = $this->counters[$key] ?? 0;
        if ($current + $by > $limit) {
            return false;
        }
        $this->counters[$key] = $current + $by;

        return true;
    }

    public function counterAdd(string $day, string $metric, int $by): void
    {
        $this->guardWrite();
        $this->queries++;
        $key = $day . '|' . $metric;
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function counterGet(string $day, string $metric): int
    {
        $this->queries++;

        return $this->counters[$day . '|' . $metric] ?? 0;
    }

    public function counterPrune(string $beforeDay): int
    {
        $n = 0;
        foreach (array_keys($this->counters) as $key) {
            if (substr($key, 0, 10) < $beforeDay) {
                unset($this->counters[$key]);
                $n++;
            }
        }

        return $n;
    }

    public function lock(string $name, int $ttl): bool
    {
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        if (isset($this->locks[$name]) && $this->locks[$name] > $now) {
            return false;
        }
        $this->locks[$name] = $now + $ttl;

        return true;
    }

    public function unlock(string $name): void
    {
        unset($this->locks[$name]);
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    /**
     * @param callable(array<string, mixed>): bool $where
     * @return list<Row>
     */
    private function select(callable $where, bool $newestFirst): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($where($r)) {
                $out[] = Row::fromDb($r);
            }
        }
        if ($newestFirst) {
            $out = array_reverse($out);
        }

        return $out;
    }
}
