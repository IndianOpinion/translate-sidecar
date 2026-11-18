<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Settings;

/**
 * The adapter registry.
 */
final class Translators
{
    /** @var array<string, Translator>|null */
    private ?array $cache = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array<string, Translator>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $list = ['manual' => new ManualTranslator()];
        $caller = apply_filters('translate_sidecar_llm_caller', null);
        if (is_callable($caller)) {
            $list['caller'] = new CallerTranslator($caller, $this->settings->int('segment_budget'));
        }
        $filtered = apply_filters('translate_sidecar_translators', $list);
        $out = [];
        foreach (is_array($filtered) ? $filtered : $list as $id => $t) {
            if ($t instanceof Translator) {
                $out[(string) $id] = $t;
            }
        }
        $this->cache = $out;

        return $out;
    }

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * The automatic adapter for a language, or null when none is chosen or
     * the choice is the manual adapter.
     */
    public function forLanguage(Language $lang): ?Translator
    {
        $id = $lang->adapter !== '' ? $lang->adapter : (string) $this->settings->get('adapter');
        if ($id === '' || $id === 'manual') {
            return null;
        }

        return $this->all()[$id] ?? null;
    }
}
