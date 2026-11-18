<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

use IndianOpinion\TranslateSidecar\Segment\Segment;
use IndianOpinion\TranslateSidecar\Segment\SegmentedPost;

/**
 * Which segments come from memory and which are sent, grouped into calls.
 */
final class Plan
{
    /**
     * @param array<string, string> $fromMemory segment id to text
     * @param list<array<string, Segment>> $calls
     */
    public function __construct(
        public readonly SegmentedPost $post,
        public readonly array $fromMemory,
        public readonly array $calls,
    ) {
    }

    /**
     * @param array<string, string> $memory segment hash to text, approved translations only
     */
    public static function build(SegmentedPost $post, array $memory, int $budget): self
    {
        $fromMemory = [];
        $send = [];
        foreach ($post->segments as $id => $seg) {
            if (isset($memory[$seg->hash])) {
                $fromMemory[$id] = $memory[$seg->hash];
            } else {
                $send[$id] = $seg;
            }
        }

        return new self($post, $fromMemory, self::group($send, $budget));
    }

    /**
     * Groups segments into calls of at most $budget source characters. A
     * segment larger than the budget goes alone.
     *
     * @param array<string, Segment> $segments
     * @return list<array<string, Segment>>
     */
    public static function group(array $segments, int $budget): array
    {
        $calls = [];
        $current = [];
        $size = 0;
        foreach ($segments as $id => $seg) {
            $len = $seg->length();
            if ($len >= $budget) {
                if ($current !== []) {
                    $calls[] = $current;
                    $current = [];
                    $size = 0;
                }
                $calls[] = [$id => $seg];
                continue;
            }
            if ($size + $len > $budget && $current !== []) {
                $calls[] = $current;
                $current = [];
                $size = 0;
            }
            $current[$id] = $seg;
            $size += $len;
        }
        if ($current !== []) {
            $calls[] = $current;
        }

        return $calls;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function sentSegments(): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            $n += count($call);
        }

        return $n;
    }

    public function sentChars(): int
    {
        $n = 0;
        foreach ($this->calls as $call) {
            foreach ($call as $seg) {
                $n += $seg->length();
            }
        }

        return $n;
    }
}
