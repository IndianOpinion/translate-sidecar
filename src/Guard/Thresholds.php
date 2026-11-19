<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Guard;

/**
 * Guard thresholds for one language.
 */
final class Thresholds
{
    public function __construct(
        public readonly float $titleShare = 0.70,
        public readonly float $bodyShare = 0.60,
        public readonly float $thirdScript = 0.05,
        public readonly float $latinShare = 0.90,
        public readonly int $smallField = 8,
        public readonly float $ratioMin = 0.4,
        public readonly float $ratioMax = 3.0,
        public readonly float $unchangedCeiling = 0.20,
        public readonly int $shortWords = 4,
        public readonly int $truncationMinChars = 200,
        public readonly float $truncationRatio = 0.25,
        public readonly bool $numbersFail = false,
    ) {
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $d = new self();

        return new self(
            (float) ($a['title_share'] ?? $d->titleShare),
            (float) ($a['body_share'] ?? $d->bodyShare),
            (float) ($a['third_script'] ?? $d->thirdScript),
            (float) ($a['latin_share'] ?? $d->latinShare),
            (int) ($a['small_field'] ?? $d->smallField),
            (float) ($a['ratio_min'] ?? $d->ratioMin),
            (float) ($a['ratio_max'] ?? $d->ratioMax),
            (float) ($a['unchanged_ceiling'] ?? $d->unchangedCeiling),
            (int) ($a['short_words'] ?? $d->shortWords),
            (int) ($a['truncation_min_chars'] ?? $d->truncationMinChars),
            (float) ($a['truncation_ratio'] ?? $d->truncationRatio),
            ($a['numbers_guard'] ?? 'warn') === 'fail' || !empty($a['numbers_fail']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title_share' => $this->titleShare,
            'body_share' => $this->bodyShare,
            'third_script' => $this->thirdScript,
            'latin_share' => $this->latinShare,
            'small_field' => $this->smallField,
            'ratio_min' => $this->ratioMin,
            'ratio_max' => $this->ratioMax,
            'unchanged_ceiling' => $this->unchangedCeiling,
            'short_words' => $this->shortWords,
            'truncation_min_chars' => $this->truncationMinChars,
            'truncation_ratio' => $this->truncationRatio,
            'numbers_guard' => $this->numbersFail ? 'fail' : 'warn',
        ];
    }
}
