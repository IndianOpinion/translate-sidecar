<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Sitemap\Cache;

/**
 * Tells caches what changed. A change to any member's hreflang block is a
 * change on every member, so the whole cluster is purged.
 */
final class Purger
{
    public function __construct(
        private readonly Translations $translations,
        private readonly Cache $sitemaps,
    ) {
    }

    /**
     * @param list<string> $langs languages whose sitemap changed
     * @param list<string> $extraUrls URLs no longer in the cluster that still need a purge
     */
    public function purge(int $postId, string $why, array $langs, array $extraUrls = []): void
    {
        $this->translations->forget($postId);
        $urls = $this->translations->purgeUrls($postId, $extraUrls);
        if ($urls !== []) {
            do_action('translate_sidecar_purge_urls', $urls, $why);
        }
        clean_post_cache($postId);
        $this->sitemaps->markDirty($langs);
    }
}
