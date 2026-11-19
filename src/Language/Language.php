<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Language;

/**
 * One registered language. Immutable.
 */
final class Language
{
    public const DEFAULT_LINK_LABEL = 'Read the original';

    /**
     * @param list<string> $scripts
     */
    public function __construct(
        public readonly string $prefix,
        public readonly string $hreflang,
        public readonly string $locale,
        public readonly string $name,
        public readonly string $nativeName,
        public readonly string $dir,
        public readonly array $scripts,
        public readonly bool $noScriptGuard,
        public readonly bool $publish,
        public readonly bool $index,
        public readonly bool $auto,
        public readonly string $originalLinkLabel,
        public readonly ?float $ratioMin = null,
        public readonly ?float $ratioMax = null,
        public readonly string $adapter = '',
    ) {
    }

    /**
     * Builds a language from a stored or filtered array, filling defaults.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $prefix = strtolower(trim((string) ($a['prefix'] ?? '')));
        $hreflang = trim((string) ($a['hreflang'] ?? $prefix));
        $scripts = $a['scripts'] ?? null;
        if (!is_array($scripts)) {
            $scripts = is_string($scripts) && $scripts !== ''
                ? array_map('trim', explode(',', $scripts))
                : \IndianOpinion\TranslateSidecar\Script\ScriptTable::scriptsFor($hreflang);
        }
        $scripts = array_values(array_filter(array_map('strval', $scripts), static fn (string $s): bool => $s !== ''));

        return new self(
            $prefix,
            $hreflang,
            trim((string) ($a['locale'] ?? str_replace('-', '_', $hreflang))),
            trim((string) ($a['name'] ?? $prefix)),
            trim((string) ($a['native_name'] ?? ($a['name'] ?? $prefix))),
            (($a['dir'] ?? 'ltr') === 'rtl') ? 'rtl' : 'ltr',
            $scripts,
            !empty($a['no_script_guard']),
            !empty($a['publish']),
            !empty($a['index']),
            !empty($a['auto']),
            trim((string) ($a['original_link_label'] ?? '')) !== ''
                ? trim((string) $a['original_link_label'])
                : self::DEFAULT_LINK_LABEL,
            isset($a['ratio_min']) && $a['ratio_min'] !== '' ? (float) $a['ratio_min'] : null,
            isset($a['ratio_max']) && $a['ratio_max'] !== '' ? (float) $a['ratio_max'] : null,
            trim((string) ($a['adapter'] ?? '')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix,
            'hreflang' => $this->hreflang,
            'locale' => $this->locale,
            'name' => $this->name,
            'native_name' => $this->nativeName,
            'dir' => $this->dir,
            'scripts' => $this->scripts,
            'no_script_guard' => $this->noScriptGuard,
            'publish' => $this->publish,
            'index' => $this->index,
            'auto' => $this->auto,
            'original_link_label' => $this->originalLinkLabel,
            'ratio_min' => $this->ratioMin,
            'ratio_max' => $this->ratioMax,
            'adapter' => $this->adapter,
        ];
    }

    public function isIndexable(): bool
    {
        return $this->publish && $this->index;
    }

    /**
     * True when two language tags name the same language: equal ignoring
     * case, or one is the other plus more subtags.
     */
    public static function tagsMatch(string $a, string $b): bool
    {
        $a = strtolower(str_replace('_', '-', trim($a)));
        $b = strtolower(str_replace('_', '-', trim($b)));
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        return str_starts_with($a, $b . '-') || str_starts_with($b, $a . '-');
    }
}
