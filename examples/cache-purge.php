<?php

/**
 * Plugin Name: translate-sidecar cache purge (example)
 * Description: Purges every URL of a hreflang cluster when it changes.
 *
 * Replace the body with your cache's purge call. The action passes the
 * original and every translation URL, because the hreflang block changed
 * on all of them.
 */

declare(strict_types=1);

add_action('translate_sidecar_purge_urls', static function (array $urls, string $why): void {
    foreach ($urls as $url) {
        // my_cache_purge_url($url);
        error_log(sprintf('purge %s (%s)', $url, $why));
    }
}, 10, 2);
