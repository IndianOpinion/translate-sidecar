<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Sitemap;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Routing;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;
use IndianOpinion\TranslateSidecar\Support\Log;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * One sitemap per indexable language (cases 71 to 77).
 */
final class Sitemaps
{
    public const HARD_CEILING = 50000;
    public const MAX_BYTES = 52428800;
    public const FAILED_OPTION = 'translate_sidecar_sitemap_failed';

    public function __construct(
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Translations $translations,
        private readonly Posts $posts,
        private readonly Store $store,
        private readonly Cache $cache,
        private readonly Clock $clock,
    ) {
    }

    public function coreEnabled(): bool
    {
        if (!function_exists('wp_sitemaps_get_server')) {
            return false;
        }
        $server = wp_sitemaps_get_server();

        return is_object($server) && method_exists($server, 'sitemaps_enabled') && (bool) $server->sitemaps_enabled();
    }

    /**
     * Languages that get a sitemap: published and indexable.
     *
     * @return array<string, Language>
     */
    public function languages(): array
    {
        if (!Routing::prettyPermalinks()) {
            return [];
        }

        return array_filter($this->registry->routable(), static fn (Language $l): bool => $l->isIndexable());
    }

    public function perPage(): int
    {
        $n = (int) apply_filters('translate_sidecar_sitemap_max_urls', $this->settings->int('sitemap_max'));

        return max(1, min(self::HARD_CEILING, $n));
    }

    public function maxPages(string $lang): int
    {
        return max(1, (int) ceil($this->store->countLiveForLang($lang) / $this->perPage()));
    }

    /**
     * Cached entries of one page. Throws when the build fails.
     *
     * @return list<array{loc:string, lastmod:string, alternates?:array<string, string>}>
     */
    public function entries(string $lang, int $page): array
    {
        return $this->cache->remember($lang, $page, fn (): array => $this->build($lang, $page));
    }

    /**
     * Builds one page: one indexed query for the rows, one batched lookup of
     * the posts. Held, unpublished and ineligible posts are left out.
     *
     * @return list<array{loc:string, lastmod:string, alternates?:array<string, string>}>
     */
    public function build(string $lang, int $page): array
    {
        $per = $this->perPage();
        $rows = $this->store->liveForLang($lang, ($page - 1) * $per, $per);
        if ($rows === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map(static fn ($r): int => $r->postId, $rows)));
        $posts = get_posts([
            'post__in' => $ids,
            'post_type' => $this->settings->postTypes(),
            'post_status' => 'publish',
            'posts_per_page' => count($ids),
            'orderby' => 'post__in',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'suppress_filters' => true,
        ]);
        $byId = [];
        foreach ($posts as $p) {
            if ($p instanceof \WP_Post) {
                $byId[(int) $p->ID] = $p;
            }
        }
        $alternates = $this->settings->bool('sitemap_alternates');
        if ($alternates) {
            // One query for every language of every post on the page,
            // instead of one per URL.
            $this->translations->primeClusters(array_keys($byId));
        }
        $entries = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $post = $byId[$row->postId] ?? null;
            if ($post === null || !$this->posts->isPubliclyViewable($post)) {
                continue;
            }
            $native = $this->posts->nativeLanguage($post);
            if ($native !== null && $native->prefix === $lang) {
                continue;
            }
            // The narrow row is enough: isHeld() reads the full row only for
            // a stale translation under hold_major, so a page costs one row
            // query plus one post query, not one query per URL.
            if ($this->translations->isHeld($row, $post)) {
                continue;
            }
            $approved = (string) $row->approvedGmt;
            $modified = (string) $post->post_modified_gmt;
            $lastmod = max($approved, $modified);
            $entry = [
                'loc' => $this->translations->buildUrl($post, $lang),
                'lastmod' => $lastmod !== '' && $lastmod !== '0000-00-00 00:00:00' ? Clock::w3cFromGmt($lastmod) : '',
            ];
            if ($alternates) {
                $entry['alternates'] = $this->translations->cluster($row->postId);
            }
            $bytes += strlen($entry['loc']) + 120;
            if ($bytes > self::MAX_BYTES) {
                break;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return list<string> languages whose last build failed recently
     */
    private function failedLanguages(): array
    {
        $failed = get_option(self::FAILED_OPTION, []);
        if (!is_array($failed)) {
            return [];
        }
        $out = [];
        foreach ($failed as $lang => $when) {
            if ((int) $when > $this->clock->now() - 3600) {
                $out[] = (string) $lang;
            }
        }

        return $out;
    }

    private function markFailed(string $lang, bool $failed): void
    {
        $all = get_option(self::FAILED_OPTION, []);
        $all = is_array($all) ? $all : [];
        if ($failed) {
            $all[$lang] = $this->clock->now();
        } else {
            unset($all[$lang]);
        }
        update_option(self::FAILED_OPTION, $all, false);
    }

    /**
     * Route names for the plugin's own sitemaps (used when core sitemaps
     * are off).
     *
     * @return array{index:string, child:string}
     */
    public function routes(): array
    {
        $routes = apply_filters('translate_sidecar_sitemap_routes', [
            'index' => 'translations-sitemap',
            'child' => 'translations-sitemap',
        ]);
        $routes = is_array($routes) ? $routes : [];

        return [
            'index' => sanitize_title((string) ($routes['index'] ?? 'translations-sitemap')),
            'child' => sanitize_title((string) ($routes['child'] ?? 'translations-sitemap')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        if ($this->coreEnabled() || !Routing::prettyPermalinks()) {
            return [];
        }
        $r = $this->routes();

        return [
            '^' . preg_quote($r['index'], '#') . '\.xml$' => 'index.php?ts_sitemap=index',
            '^' . preg_quote($r['child'], '#') . '-([a-z]{2,3}(?:-[a-z0-9]{2,8})?)-([0-9]+)\.xml$' => 'index.php?ts_sitemap=$matches[1]:$matches[2]',
        ];
    }

    public function indexUrl(): string
    {
        return home_url('/' . $this->routes()['index'] . '.xml');
    }

    public function childUrl(string $lang, int $page): string
    {
        return home_url('/' . $this->routes()['child'] . '-' . $lang . '-' . $page . '.xml');
    }

    /**
     * Response for one of the plugin's own sitemap routes.
     *
     * @return array{status:int, body:string, headers:array<string, string>}
     */
    public function response(string $what): array
    {
        $xmlHeaders = ['Content-Type' => 'application/xml; charset=UTF-8', 'X-Robots-Tag' => 'noindex, follow'];
        if (!Routing::prettyPermalinks()) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        if ($what === 'index') {
            return ['status' => 200, 'body' => $this->indexXml(), 'headers' => $xmlHeaders];
        }
        if (preg_match('/^([a-z0-9-]+):([0-9]+)$/', $what, $m) !== 1) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        $lang = $m[1];
        $page = (int) $m[2];
        if (!isset($this->languages()[$lang]) || $page < 1) {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }
        try {
            if ($page > $this->maxPages($lang)) {
                return ['status' => 404, 'body' => '', 'headers' => []];
            }
            $entries = $this->entries($lang, $page);
            $this->markFailed($lang, false);
        } catch (\Throwable $e) {
            Log::error('sitemap ' . $lang, $e);
            $this->markFailed($lang, true);

            return ['status' => 503, 'body' => '', 'headers' => ['Retry-After' => '600']];
        }

        return ['status' => 200, 'body' => $this->urlsetXml($entries), 'headers' => $xmlHeaders];
    }

    public function indexXml(): string
    {
        $failed = $this->failedLanguages();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (array_keys($this->languages()) as $lang) {
            if (in_array($lang, $failed, true)) {
                continue;
            }
            try {
                $pages = $this->maxPages($lang);
            } catch (\Throwable $e) {
                Log::error('sitemap index ' . $lang, $e);
                continue;
            }
            for ($p = 1; $p <= $pages; $p++) {
                $xml .= '<sitemap><loc>' . esc_url($this->childUrl($lang, $p)) . "</loc></sitemap>\n";
            }
        }

        return $xml . "</sitemapindex>\n";
    }

    /**
     * @param list<array{loc:string, lastmod:string, alternates?:array<string, string>}> $entries
     */
    public function urlsetXml(array $entries): string
    {
        $alternates = $this->settings->bool('sitemap_alternates');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ($alternates ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '') . ">\n";
        foreach ($entries as $e) {
            $xml .= '<url><loc>' . esc_url($e['loc']) . '</loc>';
            if ($e['lastmod'] !== '') {
                $xml .= '<lastmod>' . esc_html($e['lastmod']) . '</lastmod>';
            }
            if ($alternates) {
                foreach ($e['alternates'] ?? [] as $tag => $url) {
                    $xml .= '<xhtml:link rel="alternate" hreflang="' . esc_attr((string) $tag) . '" href="' . esc_url((string) $url) . '"/>';
                }
            }
            $xml .= "</url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /**
     * Adds the index to robots.txt when the plugin serves its own sitemaps.
     */
    public function robotsTxt(mixed $output, mixed $public = true): mixed
    {
        if (!is_string($output) || !$public || $this->coreEnabled() || $this->languages() === []) {
            return $output;
        }

        return rtrim($output) . "\nSitemap: " . esc_url_raw($this->indexUrl()) . "\n";
    }

    /**
     * URL list for the core provider. Fails closed: an error yields no
     * entries and marks the language failed.
     *
     * @return list<array<string, string>>
     */
    public function coreUrlList(int $page, string $lang): array
    {
        if (!isset($this->languages()[$lang])) {
            return [];
        }
        try {
            $entries = $this->entries($lang, $page);
            $this->markFailed($lang, false);
        } catch (\Throwable $e) {
            Log::error('sitemap ' . $lang, $e);
            $this->markFailed($lang, true);

            return [];
        }
        $out = [];
        foreach ($entries as $e) {
            $item = ['loc' => $e['loc']];
            if ($e['lastmod'] !== '') {
                $item['lastmod'] = $e['lastmod'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Subtypes for the core provider: indexable languages that did not fail.
     *
     * @return array<string, object>
     */
    public function coreSubtypes(): array
    {
        $failed = $this->failedLanguages();
        $out = [];
        foreach ($this->languages() as $prefix => $lang) {
            if (!in_array($prefix, $failed, true)) {
                $out[$prefix] = (object) ['name' => $prefix, 'label' => $lang->name];
            }
        }

        return $out;
    }
}
