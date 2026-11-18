<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Language;

use IndianOpinion\TranslateSidecar\Script\ScriptTable;
use IndianOpinion\TranslateSidecar\Settings;

/**
 * The language registry: stored entries plus the languages filter.
 */
final class Registry
{
    public const OPTION = 'translate_sidecar_registry';
    public const FLUSH_OPTION = 'translate_sidecar_flush_rewrite';

    /** Bases that a prefix may never take. */
    public const RESERVED = [
        'wp-json', 'wp-admin', 'wp-content', 'wp-includes', 'wp-login.php', 'feed', 'search',
        'author', 'category', 'tag', 'page', 'comments', 'embed', 'trackback', 'attachment',
        'translations-sitemap.xml',
    ];

    /** @var array<string, Language>|null */
    private ?array $cache = null;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Every registered language, keyed by prefix.
     *
     * @return array<string, Language>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        /** @var mixed $filtered */
        $filtered = apply_filters('translate_sidecar_languages', $stored);
        $entries = is_array($filtered) ? $filtered : $stored;
        $out = [];
        $hreflangs = [];
        foreach ($entries as $entry) {
            if ($entry instanceof Language) {
                $lang = $entry;
            } elseif (is_array($entry)) {
                $lang = Language::fromArray($entry);
            } else {
                continue;
            }
            if (!self::prefixIsWellFormed($lang->prefix) || !self::hreflangIsWellFormed($lang->hreflang)) {
                continue;
            }
            $key = strtolower($lang->hreflang);
            if (isset($out[$lang->prefix]) || isset($hreflangs[$key])) {
                continue;
            }
            if ($this->settings->sourcePrefix() !== '' && $lang->prefix === $this->settings->sourcePrefix()) {
                continue;
            }
            $hreflangs[$key] = true;
            $out[$lang->prefix] = $lang;
        }
        $this->cache = $out;

        return $out;
    }

    public function get(string $prefix): ?Language
    {
        return $this->all()[strtolower($prefix)] ?? null;
    }

    /**
     * The site source language as a routable entry, when the operator has
     * given it a prefix. Only translations of posts written natively in
     * another language are routed there (case 12).
     */
    public function sourceEntry(): ?Language
    {
        $prefix = $this->settings->sourcePrefix();
        if ($prefix === '' || !self::prefixIsWellFormed($prefix)) {
            return null;
        }
        $tag = $this->settings->sourceLanguage();

        return Language::fromArray([
            'prefix' => $prefix,
            'hreflang' => $tag,
            'locale' => $this->settings->sourceLocale(),
            'name' => $tag,
            'publish' => $this->settings->bool('source_publish'),
            'index' => $this->settings->bool('source_index'),
            'scripts' => \IndianOpinion\TranslateSidecar\Script\ScriptTable::scriptsFor($tag),
            'no_script_guard' => \IndianOpinion\TranslateSidecar\Script\ScriptTable::scriptsFor($tag) === [],
        ]);
    }

    /**
     * Every routable language: the registry plus the source entry.
     *
     * @return array<string, Language>
     */
    public function routable(): array
    {
        $all = $this->all();
        $source = $this->sourceEntry();
        if ($source !== null && !isset($all[$source->prefix])) {
            $all[$source->prefix] = $source;
        }

        return $all;
    }

    /**
     * A registered language, or the source entry, by prefix.
     */
    public function resolve(string $prefix): ?Language
    {
        return $this->routable()[strtolower($prefix)] ?? null;
    }

    /**
     * Languages whose routes are live (publish on).
     *
     * @return array<string, Language>
     */
    public function published(): array
    {
        return array_filter($this->all(), static fn (Language $l): bool => $l->publish);
    }

    /**
     * Languages that are published and indexable.
     *
     * @return array<string, Language>
     */
    public function indexable(): array
    {
        return array_filter($this->all(), static fn (Language $l): bool => $l->isIndexable());
    }

    /**
     * Finds a registered language by its hreflang tag.
     */
    public function byHreflang(string $tag): ?Language
    {
        foreach ($this->all() as $lang) {
            if (strtolower($lang->hreflang) === strtolower($tag)) {
                return $lang;
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->cache = null;
    }

    public static function prefixIsWellFormed(string $prefix): bool
    {
        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $prefix) === 1;
    }

    /**
     * Well-formed BCP 47 tag (language, optional script, region, variants,
     * extensions). `x-default` is refused.
     */
    public static function hreflangIsWellFormed(string $tag): bool
    {
        if (strtolower($tag) === 'x-default') {
            return false;
        }
        $re = '/^[a-zA-Z]{2,3}(-[a-zA-Z]{4})?(-(?:[a-zA-Z]{2}|[0-9]{3}))?'
            . '(-(?:[a-zA-Z0-9]{5,8}|[0-9][a-zA-Z0-9]{3}))*(-[a-wy-zA-WY-Z0-9](-[a-zA-Z0-9]{2,8})+)*$/';

        return preg_match($re, $tag) === 1;
    }

    /**
     * Validates one entry against the registry. Returns a list of error
     * messages; an empty list means the entry can be saved.
     *
     * @param array<string, mixed> $entry
     * @param string|null $replacing prefix of the entry being edited, if any
     * @return list<string>
     */
    public function validate(array $entry, ?string $replacing = null): array
    {
        $errors = [];
        $lang = Language::fromArray($entry);
        $prefix = $lang->prefix;
        if (!self::prefixIsWellFormed($prefix)) {
            $errors[] = sprintf('The prefix "%s" is malformed. Use two or three lower-case letters, optionally followed by a hyphen and 2 to 8 letters or digits.', $prefix);

            return $errors;
        }
        if (strtolower($lang->hreflang) === 'x-default') {
            $errors[] = 'The hreflang tag "x-default" is reserved.';
        } elseif (!self::hreflangIsWellFormed($lang->hreflang)) {
            $errors[] = sprintf('The hreflang tag "%s" is not a well-formed BCP 47 tag.', $lang->hreflang);
        }
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($stored as $other) {
            if (!is_array($other)) {
                continue;
            }
            $o = Language::fromArray($other);
            if ($replacing !== null && $o->prefix === $replacing) {
                continue;
            }
            if ($o->prefix === $prefix) {
                $errors[] = sprintf('The prefix "%s" is already registered.', $prefix);
            }
            if (strtolower($o->hreflang) === strtolower($lang->hreflang)) {
                $errors[] = sprintf('The hreflang tag "%s" is already registered for prefix "%s".', $lang->hreflang, $o->prefix);
            }
        }
        $sourcePrefix = $this->settings->sourcePrefix();
        if (($sourcePrefix !== '' && $prefix === $sourcePrefix) || strtolower($lang->hreflang) === strtolower($this->settings->sourceLanguage())) {
            $errors[] = sprintf('The prefix "%s" is the site source language.', $prefix);
        }
        foreach ($this->collisions($prefix) as $collision) {
            $errors[] = sprintf('The prefix "%s" collides with %s.', $prefix, $collision);
        }
        if ($lang->scripts === [] && !$lang->noScriptGuard) {
            $errors[] = 'Choose at least one script for this language, or tick "no script guard" (this turns off the script check for it).';
        }
        $known = array_keys((array) apply_filters('translate_sidecar_script_ranges', ScriptTable::defaults()));
        foreach ($lang->scripts as $script) {
            if (!in_array($script, $known, true)) {
                $errors[] = sprintf('Unknown script "%s".', $script);
            }
        }

        return $errors;
    }

    /**
     * Names of everything the prefix collides with.
     *
     * @return list<string>
     */
    public function collisions(string $prefix): array
    {
        $found = [];
        $reserved = self::RESERVED;
        foreach (['category_base' => 'category', 'tag_base' => 'tag'] as $option => $default) {
            $base = trim((string) get_option($option, ''), '/');
            $reserved[] = $base !== '' ? strtok($base, '/') : $default;
        }
        if (in_array($prefix, $reserved, true)) {
            $found[] = sprintf('the reserved base "%s"', $prefix);
        }
        $page = get_page_by_path($prefix, OBJECT, 'page');
        if ($page instanceof \WP_Post && (int) $page->post_parent === 0) {
            $found[] = sprintf('the top-level page "%s" (ID %d)', $prefix, (int) $page->ID);
        }
        global $wp_rewrite;
        if (is_object($wp_rewrite) && method_exists($wp_rewrite, 'wp_rewrite_rules')) {
            $rules = $wp_rewrite->wp_rewrite_rules();
            if (is_array($rules)) {
                foreach (array_keys($rules) as $regex) {
                    $regex = (string) $regex;
                    $plain = ltrim($regex, '^');
                    if (str_starts_with($plain, $prefix . '/') || str_starts_with($plain, $prefix . '(') || $plain === $prefix . '/?$') {
                        $query = (string) $rules[$regex];
                        if (!str_contains($query, 'ts_lang=')) {
                            $found[] = sprintf('the rewrite rule "%s"', $regex);
                            break;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Adds or replaces an entry after validation.
     *
     * @param array<string, mixed> $entry
     * @return list<string> errors; empty on success
     */
    public function save(array $entry, ?string $replacing = null): array
    {
        $errors = $this->validate($entry, $replacing);
        if ($errors !== []) {
            return $errors;
        }
        $lang = Language::fromArray($entry);
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $next = [];
        foreach ($stored as $other) {
            if (!is_array($other)) {
                continue;
            }
            $prefix = (string) ($other['prefix'] ?? '');
            if ($prefix === $lang->prefix || ($replacing !== null && $prefix === $replacing)) {
                continue;
            }
            $next[] = $other;
        }
        $next[] = $lang->toArray();
        update_option(self::OPTION, $next, true);
        $this->scheduleFlush();
        $this->reset();

        return [];
    }

    /**
     * Removes a language from the registry. Rows stay until purged.
     */
    public function remove(string $prefix): bool
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $next = array_values(array_filter(
            $stored,
            static fn ($e): bool => !(is_array($e) && ($e['prefix'] ?? '') === $prefix)
        ));
        if (count($next) === count($stored)) {
            return false;
        }
        update_option(self::OPTION, $next, true);
        $this->scheduleFlush();
        $this->reset();

        return true;
    }

    /**
     * Updates one switch (publish, index, auto) of a stored language.
     */
    public function setSwitch(string $prefix, string $switch, bool $on): bool
    {
        if (!in_array($switch, ['publish', 'index', 'auto'], true)) {
            return false;
        }
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        $changed = false;
        foreach ($stored as $i => $entry) {
            if (is_array($entry) && ($entry['prefix'] ?? '') === $prefix) {
                $stored[$i][$switch] = $on;
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::OPTION, array_values($stored), true);
            $this->scheduleFlush();
            $this->reset();
        }

        return $changed;
    }

    /**
     * Marks rewrite rules for one flush on the next request.
     */
    public function scheduleFlush(): void
    {
        update_option(self::FLUSH_OPTION, 1, true);
    }

    /**
     * Runs the scheduled flush, once. Returns true when it flushed.
     */
    public function maybeFlush(): bool
    {
        if ((int) get_option(self::FLUSH_OPTION, 0) !== 1) {
            return false;
        }
        delete_option(self::FLUSH_OPTION);
        flush_rewrite_rules(false);

        return true;
    }
}
