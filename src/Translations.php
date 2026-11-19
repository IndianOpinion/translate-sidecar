<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;

/**
 * Read side of translations: live rows with a per-request cache,
 * staleness, routability, URLs and hreflang clusters.
 */
final class Translations
{
    /** @var array<int, array<string, Row>> */
    private array $live = [];
    /** @var array<string, array<int, Row|null>> lang to post id to row, from batched lookups */
    private array $primed = [];
    /** @var array<string, bool> */
    private array $held = [];

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
    ) {
    }

    /**
     * Every live row of a post keyed by language. One query per post per
     * request.
     *
     * @return array<string, Row>
     */
    public function liveRows(int $postId): array
    {
        if (!isset($this->live[$postId])) {
            $rows = [];
            foreach ($this->store->liveForPost($postId) as $row) {
                $rows[$row->lang] = $row;
            }
            $this->live[$postId] = $rows;
        }

        return $this->live[$postId];
    }

    /**
     * Seeds the per-request cache for one language from a batched query.
     * Posts in `$postIds` without a row are remembered as having none. The
     * full per-post list (liveRows) is not touched, so a later cluster
     * lookup for one of these posts still sees every language.
     *
     * @param array<int, Row> $rowsByPost
     * @param list<int>       $postIds
     */
    public function prime(string $lang, array $rowsByPost, array $postIds = []): void
    {
        foreach ($postIds as $postId) {
            $this->primed[$lang][(int) $postId] = null;
        }
        foreach ($rowsByPost as $postId => $row) {
            $this->primed[$lang][(int) $postId] = $row;
        }
    }

    /**
     * Loads the live rows of many posts in one query, for listings that
     * need each post's cluster (sitemap alternates). The rows are narrow:
     * enough for routing and clusters, not for rendering, so only listing
     * code calls this, never a page render.
     *
     * @param list<int> $postIds
     */
    public function primeClusters(array $postIds): void
    {
        $missing = array_values(array_filter($postIds, fn (int $id): bool => !isset($this->live[$id])));
        if ($missing === []) {
            return;
        }
        foreach ($missing as $id) {
            $this->live[$id] = [];
        }
        foreach ($this->store->liveNarrowForPosts($missing) as $row) {
            $this->live[$row->postId][$row->lang] = $row;
        }
    }

    public function live(int $postId, string $lang): ?Row
    {
        if (!isset($this->live[$postId]) && isset($this->primed[$lang]) && array_key_exists($postId, $this->primed[$lang])) {
            return $this->primed[$lang][$postId];
        }

        return $this->liveRows($postId)[$lang] ?? null;
    }

    public function forget(int $postId): void
    {
        unset($this->live[$postId]);
        foreach (array_keys($this->primed) as $lang) {
            unset($this->primed[$lang][$postId]);
        }
        foreach (array_keys($this->held) as $key) {
            if (str_starts_with($key, $postId . ':')) {
                unset($this->held[$key]);
            }
        }
    }

    public function isStale(Row $row, \WP_Post $post): bool
    {
        return $row->sourceHash !== $this->posts->sourceFields($post)->hash();
    }

    /**
     * True when the stale policy stops this live row from routing.
     */
    public function isHeld(Row $row, \WP_Post $post): bool
    {
        $key = $post->ID . ':' . $row->lang . ':' . $row->id;
        if (isset($this->held[$key])) {
            return $this->held[$key];
        }
        $held = false;
        if ($this->isStale($row, $post)) {
            $policy = (string) $this->settings->get('stale_policy');
            if ($policy === 'hold') {
                $held = true;
            } elseif ($policy === 'hold_major') {
                // A narrow listing row has no segments; read the full row.
                $full = $row->partial ? $this->store->find($row->id) : $row;
                $held = $full === null || $full->state !== Row::LIVE || $this->isMajorChange($full, $post);
            }
        }

        return $this->held[$key] = $held;
    }

    /**
     * A change is major when the title changed or more than the configured
     * share of body segments changed.
     */
    public function isMajorChange(Row $row, \WP_Post $post): bool
    {
        $current = $this->posts->segmenter()->segment($this->posts->sourceFields($post));
        $old = [];
        $oldTitle = null;
        foreach ($row->segments as $s) {
            if ($s['id'] === 'title') {
                $oldTitle = $s['hash'];
            }
            $old[$s['hash']] = true;
        }
        $title = $current->segments['title'] ?? null;
        if ($title !== null && $oldTitle !== null && $title->hash !== $oldTitle) {
            return true;
        }
        $body = $current->byField('body');
        if ($body === []) {
            return false;
        }
        $changed = 0;
        foreach ($body as $seg) {
            if (!isset($old[$seg->hash])) {
                $changed++;
            }
        }
        $limit = (float) apply_filters('translate_sidecar_major_change_share', $this->settings->float('stale_major_share'), $post);

        return $changed / count($body) > $limit;
    }

    /**
     * The live row that may be served at /xx/slug/, or null. Fails closed.
     */
    public function routable(int $postId, string $lang): ?Row
    {
        $language = $this->registry->resolve($lang);
        if ($language === null || !$language->publish || !Routing::prettyPermalinks()) {
            return null;
        }
        $post = $this->posts->get($postId);
        if ($post === null || !$this->posts->isTranslatableType((string) $post->post_type) || !$this->posts->isPubliclyViewable($post)) {
            return null;
        }
        $native = $this->posts->nativeLanguage($post);
        if ($native !== null && $native->prefix === $lang) {
            return null;
        }
        $row = $this->live($postId, $lang);
        if ($row === null || $this->isHeld($row, $post)) {
            return null;
        }

        return $row;
    }

    /**
     * Absolute URL of a translation, following the permalink structure's
     * trailing-slash rule.
     */
    public function buildUrl(\WP_Post $post, string $prefix): string
    {
        $type = get_post_type_object((string) $post->post_type);
        $hierarchical = is_object($type) && !empty($type->hierarchical);
        $path = $hierarchical ? (string) get_page_uri($post) : (string) $post->post_name;
        $path = trim($path, '/');

        return home_url(user_trailingslashit($prefix . '/' . $path, 'single'));
    }

    public function url(int $postId, string $lang): ?string
    {
        if ($this->routable($postId, $lang) === null) {
            return null;
        }
        $post = $this->posts->get($postId);

        return $post === null ? null : $this->buildUrl($post, $lang);
    }

    /**
     * Languages with a routed translation of this post, keyed by prefix.
     *
     * @return array<string, Language>
     */
    public function routedLanguages(int $postId): array
    {
        $out = [];
        foreach ($this->registry->routable() as $prefix => $lang) {
            if (isset($this->liveRows($postId)[$prefix]) && $this->routable($postId, $prefix) !== null) {
                $out[$prefix] = $lang;
            }
        }

        return $out;
    }

    /**
     * The hreflang cluster: hreflang tag to absolute URL. Holds the
     * original, every indexable routed translation and x-default. Empty
     * when only the original would be in it. Identical on every member.
     *
     * @return array<string, string>
     */
    public function cluster(int $postId): array
    {
        $post = $this->posts->get($postId);
        if ($post === null || !$this->posts->isPubliclyViewable($post) || !Routing::prettyPermalinks()) {
            return [];
        }
        $original = (string) get_permalink($post);
        $cluster = [$this->posts->sourceLanguage($post) => $original];
        foreach ($this->routedLanguages($postId) as $prefix => $lang) {
            if ($lang->isIndexable()) {
                $cluster[$lang->hreflang] = $this->buildUrl($post, $prefix);
            }
        }
        if (count($cluster) < 2) {
            return [];
        }
        $xDefault = apply_filters('translate_sidecar_x_default', $original, $postId);
        if (is_string($xDefault) && $xDefault !== '') {
            $cluster['x-default'] = $xDefault;
        }
        $filtered = apply_filters('translate_sidecar_cluster', $cluster, $postId);

        return is_array($filtered) ? array_map('strval', $filtered) : $cluster;
    }

    /**
     * Every URL whose head output depends on this post's cluster: the
     * original and every published translation, indexed or not.
     *
     * @param list<string> $extra
     * @return list<string>
     */
    public function purgeUrls(int $postId, array $extra = []): array
    {
        $post = $this->posts->get($postId);
        if ($post === null) {
            return array_values(array_unique($extra));
        }
        $urls = [(string) get_permalink($post)];
        foreach ($this->liveRows($postId) as $prefix => $row) {
            $lang = $this->registry->resolve($prefix);
            if ($lang !== null && $lang->publish) {
                $urls[] = $this->buildUrl($post, $prefix);
            }
        }

        return array_values(array_unique(array_merge($urls, $extra)));
    }
}
