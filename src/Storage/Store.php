<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Storage;

/**
 * Persistence for rows, translation memory, counters and named locks.
 */
interface Store
{
    /** Writes are allowed only when the schema has been verified. */
    public function isWritable(): bool;

    public function insert(Row $row): int;

    public function find(int $id): ?Row;

    /**
     * @param list<string>|null $states
     * @return list<Row> newest first
     */
    public function rowsFor(int $postId, string $lang, ?array $states = null): array;

    /**
     * Every live row of one post, all languages, in one query.
     *
     * @return list<Row>
     */
    public function liveForPost(int $postId): array;

    /**
     * Live rows of several posts in one language, in one query.
     *
     * @param list<int> $postIds
     * @return array<int, Row> keyed by post id
     */
    public function liveForPosts(array $postIds, string $lang): array;

    /**
     * Live rows of several posts in every language, in one query, without
     * their text (narrow rows, `partial` set). For listings such as sitemap
     * alternates.
     *
     * @param list<int> $postIds
     * @return list<Row>
     */
    public function liveNarrowForPosts(array $postIds): array;

    /** A queued or running job for this post and language. */
    public function activeJob(int $postId, string $lang): ?Row;

    /**
     * Queued jobs whose next attempt is due, oldest first.
     *
     * @return list<Row>
     */
    public function dueJobs(string $nowGmt, int $limit, ?string $lang = null): array;

    /**
     * Puts back jobs left `running` since before `$beforeGmt` (the process
     * died mid-call) as `queued`, counting an attempt; a job that already
     * used `$maxAttempts` fails with reason `stalled`. Returns the number
     * of rows changed.
     */
    public function recoverStalled(string $beforeGmt, int $maxAttempts, string $nowGmt): int;

    /**
     * Updates columns. With $expectedState the update is conditional on the
     * current state and returns false when another request moved it first.
     *
     * @param array<string, mixed> $changes column name to value
     */
    public function update(int $id, array $changes, ?string $expectedState = null): bool;

    /**
     * @return list<int> ids of deleted rows
     */
    public function deleteForPost(int $postId): array;

    public function deleteForLang(string $lang): int;

    /**
     * Live rows of one language, newest approval first.
     *
     * @return list<Row>
     */
    public function liveForLang(string $lang, int $offset, int $limit): array;

    public function countLiveForLang(string $lang): int;

    /**
     * @return array<string, array<string, int>> language to state to count
     */
    public function stateCounts(): array;

    /**
     * @return list<Row>
     */
    public function recentFailures(int $limit): array;

    /**
     * Candidates, oldest first, optionally for one language.
     *
     * @return list<Row>
     */
    public function candidates(?string $lang, int $offset, int $limit): array;

    /**
     * Deletes rows in the given states last updated before the date.
     *
     * @param list<string> $states
     */
    public function prune(array $states, string $beforeGmt): int;

    /**
     * @param list<string> $hashes
     * @return array<string, string> hash to text
     */
    public function memoryGet(string $lang, array $hashes): array;

    /**
     * @param array<string, string> $entries hash to text
     */
    public function memoryPut(string $lang, array $entries, int $rowId, string $nowGmt): void;

    public function memoryPrune(string $beforeGmt): int;

    /**
     * @param list<int> $rowIds
     */
    public function memoryDeleteForRows(array $rowIds): int;

    /**
     * Atomically adds $by to a counter when the result stays at or below
     * $limit. Returns false, and changes nothing, when it would not.
     */
    public function counterTryAdd(string $day, string $metric, int $by, int $limit): bool;

    public function counterAdd(string $day, string $metric, int $by): void;

    public function counterGet(string $day, string $metric): int;

    public function counterPrune(string $beforeDay): int;

    /** Takes a named lock for at most $ttl seconds. */
    public function lock(string $name, int $ttl): bool;

    public function unlock(string $name): void;

    /** Number of queries run so far, for the performance budget. */
    public function queryCount(): int;
}
