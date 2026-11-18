<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

/**
 * A post cut into segments, plus everything needed to put a translation
 * back into the original structure.
 */
final class SegmentedPost
{
    /**
     * @param array<string, Segment> $segments in order, keyed by id
     * @param list<string|array{seg:string}> $bodyParts literal markup or a segment reference
     * @param array<int, string> $sentinels raw markup restored at the end
     * @param array<int, array{segment:?string, value:string}> $alts alt sentinel number to segment
     * @param array<string, string> $fields field name to plain source text
     */
    public function __construct(
        public readonly array $segments,
        public readonly array $bodyParts,
        public readonly array $sentinels,
        public readonly array $alts,
        public readonly array $fields,
        public readonly string $sourceHash,
        public readonly bool $hasManualExcerpt,
    ) {
    }

    /**
     * @return array<string, Segment>
     */
    public function byField(string $field): array
    {
        return array_filter($this->segments, static fn (Segment $s): bool => $s->field === $field);
    }

    public function count(): int
    {
        return count($this->segments);
    }

    /**
     * Hash of every segment, keyed by id. Used for staleness and memory.
     *
     * @return array<string, string>
     */
    public function hashes(): array
    {
        return array_map(static fn (Segment $s): string => $s->hash, $this->segments);
    }
}
