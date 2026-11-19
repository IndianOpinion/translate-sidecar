<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Support;

use IndianOpinion\TranslateSidecar\Support\Clock;

final class FakeClock extends Clock
{
    public function __construct(public int $time = 1_793_000_000)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
