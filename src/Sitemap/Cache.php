<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Sitemap;

use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;

/**
 * Cache of sitemap URL lists. The built-at time decides freshness, never
 * the presence of content, so an empty list is a valid cached answer.
 */
final class Cache
{
    public const DIRTY_OPTION = 'translate_sidecar_sitemap_dirty';
    public const PREFIX = 'translate_sidecar_sitemap_';
    public const MAX_AGE = 86400;

    public function __construct(private readonly Store $store, private readonly Clock $clock)
    {
    }

    private static function key(string $lang, int $page): string
    {
        return self::PREFIX . $lang . '_' . $page;
    }

    /**
     * @param list<string> $langs
     */
    public function markDirty(array $langs): void
    {
        if ($langs === []) {
            return;
        }
        $dirty = get_option(self::DIRTY_OPTION, []);
        $dirty = is_array($dirty) ? $dirty : [];
        // One second ahead so a build in the same second counts as stale.
        $now = $this->clock->now() + 1;
        foreach ($langs as $lang) {
            $dirty[$lang] = $now;
        }
        update_option(self::DIRTY_OPTION, $dirty, false);
    }

    public function dirtyAt(string $lang): int
    {
        $dirty = get_option(self::DIRTY_OPTION, []);

        return is_array($dirty) ? (int) ($dirty[$lang] ?? 0) : 0;
    }

    /**
     * @return array{built:int, entries:list<array{loc:string, lastmod:string, alternates?:array<string, string>}>}|null
     */
    public function read(string $lang, int $page): ?array
    {
        $v = get_option(self::key($lang, $page), null);
        if (!is_array($v) || !isset($v['built'], $v['entries']) || !is_array($v['entries'])) {
            return null;
        }

        /** @var array{built:int, entries:list<array{loc:string, lastmod:string, alternates?:array<string, string>}>} $v */
        return $v;
    }

    /**
     * @param array{built:int, entries:list<array<string, mixed>>}|null $cached
     */
    public function isFresh(?array $cached, string $lang): bool
    {
        if ($cached === null) {
            return false;
        }
        $built = (int) $cached['built'];

        return $built >= $this->dirtyAt($lang) && $built > $this->clock->now() - self::MAX_AGE;
    }

    /**
     * Returns a fresh list, rebuilding under a named lock when needed. When
     * another request holds the lock, serves the previous list; builds
     * without the lock only when there is none.
     *
     * @param callable(): list<array{loc:string, lastmod:string, alternates?:array<string, string>}> $build
     * @return list<array{loc:string, lastmod:string, alternates?:array<string, string>}>
     */
    public function remember(string $lang, int $page, callable $build): array
    {
        $cached = $this->read($lang, $page);
        if ($this->isFresh($cached, $lang)) {
            return $cached['entries'] ?? [];
        }
        $lock = 'sitemap_' . $lang . '_' . $page;
        if (!$this->store->lock($lock, 60)) {
            if ($cached !== null) {
                return $cached['entries'];
            }

            return $build();
        }
        try {
            $entries = $build();
            update_option(self::key($lang, $page), ['built' => $this->clock->now(), 'entries' => $entries], false);

            return $entries;
        } finally {
            $this->store->unlock($lock);
        }
    }
}
