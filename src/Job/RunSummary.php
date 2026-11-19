<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

/**
 * What one tick did.
 */
final class RunSummary
{
    public int $processed = 0;
    public int $candidates = 0;
    public int $failed = 0;
    public int $requeued = 0;
    public ?string $stopped = null;
    /** @var array<int, string> job id to outcome */
    public array $outcomes = [];
    public float $seconds = 0.0;

    public function add(int $jobId, string $outcome): void
    {
        $this->processed++;
        $this->outcomes[$jobId] = $outcome;
        if ($outcome === 'candidate') {
            $this->candidates++;
        } elseif ($outcome === 'failed') {
            $this->failed++;
        } else {
            $this->requeued++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'candidates' => $this->candidates,
            'failed' => $this->failed,
            'requeued' => $this->requeued,
            'stopped' => $this->stopped,
            'outcomes' => $this->outcomes,
            'seconds' => round($this->seconds, 3),
        ];
    }
}
