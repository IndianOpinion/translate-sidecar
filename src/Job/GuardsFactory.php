<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

use IndianOpinion\TranslateSidecar\Guard\Guards;
use IndianOpinion\TranslateSidecar\Guard\Thresholds;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Script\ScriptTable;
use IndianOpinion\TranslateSidecar\Settings;

/**
 * Builds guards and per-language thresholds from settings and filters.
 */
final class GuardsFactory
{
    private ?ScriptCounter $counter = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function counter(): ScriptCounter
    {
        if ($this->counter === null) {
            $table = apply_filters('translate_sidecar_script_ranges', ScriptTable::defaults());
            $this->counter = new ScriptCounter(is_array($table) ? $table : ScriptTable::defaults());
        }

        return $this->counter;
    }

    public function make(): Guards
    {
        $detector = static function (string $text): ?string {
            $tag = apply_filters('translate_sidecar_detect_language', null, $text);

            return is_string($tag) && $tag !== '' ? $tag : null;
        };

        return new Guards($this->counter(), array_values(array_map('strval', (array) $this->settings->get('do_not_translate'))), $detector);
    }

    public function thresholds(Language $lang): Thresholds
    {
        $s = $this->settings;
        $base = [
            'title_share' => $s->float('title_share'),
            'body_share' => $s->float('body_share'),
            'third_script' => $s->float('third_script'),
            'ratio_min' => $lang->ratioMin ?? $s->float('ratio_min'),
            'ratio_max' => $lang->ratioMax ?? $s->float('ratio_max'),
            'unchanged_ceiling' => $s->float('unchanged_ceiling'),
            'numbers_guard' => (string) $s->get('numbers_guard'),
        ];
        $filtered = apply_filters('translate_sidecar_guard_thresholds', $base, $lang->prefix);

        return Thresholds::fromArray(is_array($filtered) ? $filtered : $base);
    }
}
