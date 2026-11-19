<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Guard;

/**
 * Result of running the guards: failures, warnings and measurements.
 */
final class GuardReport
{
    /** @var list<array{reason:string, field:string, detail:array<string, mixed>}> */
    private array $failures = [];
    /** @var list<array{reason:string, field:string, detail:array<string, mixed>}> */
    private array $warnings = [];
    /** @var array<string, mixed> */
    private array $metrics = [];

    /**
     * @param array<string, mixed> $detail
     */
    public function fail(string $reason, string $field = '', array $detail = []): void
    {
        $this->failures[] = ['reason' => $reason, 'field' => $field, 'detail' => $detail];
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function warn(string $reason, string $field = '', array $detail = []): void
    {
        $this->warnings[] = ['reason' => $reason, 'field' => $field, 'detail' => $detail];
    }

    public function metric(string $key, mixed $value): void
    {
        $this->metrics[$key] = $value;
    }

    public function passed(): bool
    {
        return $this->failures === [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function firstReason(): ?string
    {
        return $this->failures[0]['reason'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function reasons(): array
    {
        return array_values(array_unique(array_map(static fn (array $f): string => $f['reason'], $this->failures)));
    }

    /**
     * @return list<array{reason:string, field:string, detail:array<string, mixed>}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @return list<array{reason:string, field:string, detail:array<string, mixed>}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * Turns every failure into a warning. Used for human translations,
     * where guards advise and the reviewer decides.
     */
    public function demote(): self
    {
        $copy = new self();
        $copy->warnings = array_merge($this->warnings, $this->failures);
        $copy->metrics = $this->metrics;

        return $copy;
    }

    public function merge(self $other): void
    {
        $this->failures = array_merge($this->failures, $other->failures);
        $this->warnings = array_merge($this->warnings, $other->warnings);
        $this->metrics = array_merge($this->metrics, $other->metrics);
    }

    /**
     * @return array{passed:bool, failures:list<array<string, mixed>>, warnings:list<array<string, mixed>>, metrics:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'failures' => $this->failures,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $r = new self();
        foreach ((array) ($a['failures'] ?? []) as $f) {
            if (is_array($f)) {
                $r->fail((string) ($f['reason'] ?? 'unknown'), (string) ($f['field'] ?? ''), (array) ($f['detail'] ?? []));
            }
        }
        foreach ((array) ($a['warnings'] ?? []) as $w) {
            if (is_array($w)) {
                $r->warn((string) ($w['reason'] ?? 'unknown'), (string) ($w['field'] ?? ''), (array) ($w['detail'] ?? []));
            }
        }
        $r->metrics = (array) ($a['metrics'] ?? []);

        return $r;
    }
}
