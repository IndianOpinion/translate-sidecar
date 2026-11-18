<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

/**
 * Plugin settings: one autoloaded option array with defaults, plus the kill
 * switch and credit pause, which are read fresh every time.
 */
final class Settings
{
    public const OPTION = 'translate_sidecar_settings';
    public const KILL_OPTION = 'translate_sidecar_disabled';
    public const NETWORK_KILL_OPTION = 'translate_sidecar_network_disabled';
    public const CREDIT_PAUSE_OPTION = 'translate_sidecar_credit_pause_until';

    public const STALE_POLICIES = ['serve', 'hold_major', 'hold'];
    public const HEAD_MODES = ['auto', 'core', 'filters', 'rewrite'];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'source_lang' => '',
            'source_prefix' => '',
            'source_publish' => false,
            'source_index' => false,
            'post_types' => ['post'],
            'min_words' => 150,
            'adapter' => '',
            'daily_cap' => 100,
            'daily_chars' => 0,
            'stale_policy' => 'hold_major',
            'stale_major_share' => 0.20,
            'translate_alt' => true,
            'numbers_guard' => 'warn',
            'title_share' => 0.70,
            'body_share' => 0.60,
            'third_script' => 0.05,
            'ratio_min' => 0.4,
            'ratio_max' => 3.0,
            'unchanged_ceiling' => 0.20,
            'head_mode' => 'auto',
            'sitemap_max' => 2000,
            'sitemap_alternates' => false,
            'tick_interval' => 300,
            'jobs_per_tick' => 5,
            'time_budget' => 50,
            'use_queue' => false,
            'retention_days' => 30,
            'max_segments' => 200,
            'segment_budget' => 6000,
            'self_approval' => true,
            'do_not_translate' => [],
            'memory_days' => 180,
            'keep_versions' => 3,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Saves validated settings. Unknown keys are dropped.
     *
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $clean = self::sanitize(array_merge($this->all(), $values));
        update_option(self::OPTION, $clean, true);
        $this->cache = null;
    }

    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    public static function sanitize(array $in): array
    {
        $d = self::defaults();
        $out = [];
        foreach ($d as $key => $default) {
            $v = $in[$key] ?? $default;
            if (is_bool($default)) {
                $out[$key] = (bool) $v;
            } elseif (is_int($default)) {
                $out[$key] = max(0, (int) $v);
            } elseif (is_float($default)) {
                $out[$key] = max(0.0, (float) $v);
            } elseif (is_array($default)) {
                if (is_string($v)) {
                    $v = preg_split('/[\r\n,]+/', $v) ?: [];
                }
                $out[$key] = array_values(array_filter(array_map(
                    static fn ($x): string => trim((string) $x),
                    is_array($v) ? $v : []
                ), static fn (string $x): bool => $x !== ''));
            } else {
                $out[$key] = trim((string) $v);
            }
        }
        if (!in_array($out['stale_policy'], self::STALE_POLICIES, true)) {
            $out['stale_policy'] = $d['stale_policy'];
        }
        if (!in_array($out['head_mode'], self::HEAD_MODES, true)) {
            $out['head_mode'] = $d['head_mode'];
        }
        if (!in_array($out['numbers_guard'], ['warn', 'fail'], true)) {
            $out['numbers_guard'] = 'warn';
        }
        $out['source_prefix'] = strtolower($out['source_prefix']);
        $out['sitemap_max'] = min(50000, max(1, $out['sitemap_max']));
        $out['jobs_per_tick'] = max(1, $out['jobs_per_tick']);
        $out['tick_interval'] = max(60, $out['tick_interval']);
        $out['max_segments'] = max(1, $out['max_segments']);
        $out['segment_budget'] = max(200, $out['segment_budget']);
        $out['keep_versions'] = max(1, min(10, $out['keep_versions']));
        if ($out['post_types'] === []) {
            $out['post_types'] = ['post'];
        }

        return $out;
    }

    /**
     * The site's source language as a BCP 47 tag.
     */
    public function sourceLanguage(): string
    {
        $tag = (string) $this->get('source_lang');
        if ($tag === '') {
            $tag = str_replace('_', '-', (string) get_locale());
        }

        return $tag !== '' ? $tag : 'en';
    }

    /**
     * The URL prefix registered for the source language, or empty.
     */
    public function sourcePrefix(): string
    {
        return (string) $this->get('source_prefix');
    }

    /**
     * The source language's Open Graph locale.
     */
    public function sourceLocale(): string
    {
        return str_replace('-', '_', $this->sourceLanguage());
    }

    public function dailyCap(): int
    {
        if (defined('TRANSLATE_SIDECAR_DAILY_CAP')) {
            return max(0, (int) constant('TRANSLATE_SIDECAR_DAILY_CAP'));
        }

        return $this->int('daily_cap');
    }

    /**
     * True when calls are allowed: not killed by option, constant or
     * network switch. Read fresh, never from a cache.
     */
    public function isEnabled(): bool
    {
        if (defined('TRANSLATE_SIDECAR_DISABLED') && constant('TRANSLATE_SIDECAR_DISABLED')) {
            return false;
        }
        wp_cache_delete(self::KILL_OPTION, 'options');
        if ((bool) get_option(self::KILL_OPTION, false)) {
            return false;
        }
        if (is_multisite() && (bool) get_site_option(self::NETWORK_KILL_OPTION, false)) {
            return false;
        }

        return true;
    }

    public function setKillSwitch(bool $on): void
    {
        update_option(self::KILL_OPTION, $on ? 1 : 0, false);
    }

    /**
     * Unix time until which calls are paused after an exhausted-credit
     * error, or 0.
     */
    public function creditPausedUntil(): int
    {
        wp_cache_delete(self::CREDIT_PAUSE_OPTION, 'options');

        return (int) get_option(self::CREDIT_PAUSE_OPTION, 0);
    }

    public function pauseCreditUntil(int $until): void
    {
        update_option(self::CREDIT_PAUSE_OPTION, $until, false);
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        $types = (array) apply_filters('translate_sidecar_post_types', (array) $this->get('post_types'));

        return array_values(array_unique(array_map('strval', $types)));
    }
}
