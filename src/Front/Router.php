<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Front;

use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Routing;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * Routes /xx/slug/ to the original post rendered as a translation, and
 * answers every other case with a redirect or a status (cases 26 to 38).
 * Fails closed: any doubt is a 302 to the original.
 */
final class Router
{
    public const QV_LANG = 'ts_lang';
    public const QV_PATH = 'ts_path';
    public const QV_SITEMAP = 'ts_sitemap';
    public const SHORT_CACHE = 'max-age=300';

    private ?Decision $decision = null;

    public function __construct(
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
        private readonly Translations $translations,
        private readonly Store $store,
    ) {
    }

    /**
     * Rewrite rules this plugin adds: regex to query.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        if (!Routing::prettyPermalinks()) {
            return [];
        }
        $rules = [];
        foreach (array_keys($this->registry->routable()) as $prefix) {
            $quoted = preg_quote($prefix, '#');
            $rules['^' . $quoted . '/(.+?)/?$'] = 'index.php?' . self::QV_LANG . '=' . $prefix . '&' . self::QV_PATH . '=$matches[1]';
            $page = get_page_by_path($prefix, OBJECT, 'page');
            if (!($page instanceof \WP_Post)) {
                $rules['^' . $quoted . '/?$'] = 'index.php?' . self::QV_LANG . '=' . $prefix . '&' . self::QV_PATH . '=';
            }
        }

        return $rules;
    }

    public function addRewriteRules(): void
    {
        foreach ($this->rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public function queryVars(array $vars): array
    {
        $vars[] = self::QV_LANG;
        $vars[] = self::QV_PATH;
        $vars[] = self::QV_SITEMAP;

        return $vars;
    }

    /**
     * The `request` filter: maps a translation URL to the original post's
     * query, or marks the request for a redirect or status.
     *
     * @param array<string, mixed> $qv
     * @return array<string, mixed>
     */
    public function onRequest(array $qv): array
    {
        $this->decision = null;
        if (!isset($qv[self::QV_LANG]) || !is_string($qv[self::QV_LANG]) || $qv[self::QV_LANG] === '') {
            return $qv;
        }
        $lang = $qv[self::QV_LANG];
        $path = isset($qv[self::QV_PATH]) && is_string($qv[self::QV_PATH]) ? $qv[self::QV_PATH] : '';
        try {
            $decision = $this->decide($lang, $path);
        } catch (\Throwable $e) {
            \IndianOpinion\TranslateSidecar\Support\Log::error('routing', $e);
            $decision = new Decision(404, $lang, 0, null, null, 'error');
        }
        $preview = $this->previewDecision($lang, $decision);
        if ($preview !== null) {
            $decision = $preview;
        }
        $this->decision = $decision;
        if ($decision->isTranslation()) {
            $post = $this->posts->get($decision->postId);
            $type = $post !== null ? (string) $post->post_type : 'post';
            $out = [self::QV_LANG => $lang];
            if ($type === 'page') {
                $out['page_id'] = $decision->postId;
            } else {
                $out['p'] = $decision->postId;
                $out['post_type'] = $type;
            }

            return $out;
        }

        return [self::QV_LANG => $lang, 'error' => '404'];
    }

    /**
     * The decision for a language prefix and path. Pure apart from reads.
     */
    public function decide(string $lang, string $path): Decision
    {
        $lang = strtolower($lang);
        $language = $this->registry->resolve($lang);
        if ($language === null || !Routing::prettyPermalinks()) {
            return new Decision(404, $lang, 0, null, null, 'unknown_language');
        }
        $path = trim(rawurldecode($path), '/');
        if ($path === '') {
            // The language home is a roadmap item: /xx/ goes to the site home.
            return new Decision(302, $lang, 0, home_url('/'), null, 'language_home', self::SHORT_CACHE);
        }
        $types = $this->settings->postTypes();
        $post = $this->findPost($path, $types);
        if ($post === null) {
            $trashed = $this->findPost(self::trashedPath($path), $types);
            if ($trashed !== null && $trashed->post_status === 'trash') {
                return $this->trashed($trashed, $lang);
            }
            $moved = $this->findByOldSlug($path, $types);
            if ($moved !== null) {
                if ($language->publish && $this->posts->isPubliclyViewable($moved)) {
                    return new Decision(301, $lang, (int) $moved->ID, $this->translations->buildUrl($moved, $lang), null, 'old_slug');
                }

                return new Decision(302, $lang, (int) $moved->ID, (string) get_permalink($moved), null, 'old_slug', self::SHORT_CACHE);
            }

            return new Decision(404, $lang, 0, null, null, 'no_post');
        }
        $id = (int) $post->ID;
        $original = (string) get_permalink($post);
        if ($post->post_status === 'trash') {
            return $this->trashed($post, $lang);
        }
        $native = $this->posts->nativeLanguage($post);
        if ($native !== null && $native->prefix === $lang) {
            return new Decision(301, $lang, $id, $original, null, 'native');
        }
        if (!$this->posts->isTranslatableType((string) $post->post_type)) {
            return new Decision(302, $lang, $id, $original, null, 'type_not_translatable', self::SHORT_CACHE);
        }
        if (!$language->publish) {
            return new Decision(302, $lang, $id, $original, null, 'language_unpublished', self::SHORT_CACHE);
        }
        if (!$this->posts->isPubliclyViewable($post)) {
            return new Decision(302, $lang, $id, $original, null, 'original_not_public', self::SHORT_CACHE);
        }
        $row = $this->translations->live($id, $lang);
        if ($row !== null) {
            if ($this->translations->isHeld($row, $post)) {
                return new Decision(302, $lang, $id, $original, null, 'held', self::SHORT_CACHE);
            }

            return new Decision(200, $lang, $id, null, $row, 'live');
        }
        if ($this->store->rowsFor($id, $lang, [Row::WITHDRAWN]) !== []) {
            return new Decision(410, $lang, $id, null, null, 'withdrawn');
        }

        return new Decision(302, $lang, $id, $original, null, 'no_translation', self::SHORT_CACHE);
    }

    /**
     * A candidate preview for a reviewer with a valid token (case 56). An
     * invalid or expired token gets the normal response.
     */
    private function previewDecision(string $lang, Decision $normal): ?Decision
    {
        // phpcs:ignore WordPress.Security.NonceVerification -- validated by an HMAC token below.
        $query = $_GET;
        if (!isset($query[Preview::PARAM_ROW]) || $normal->postId === 0) {
            return null;
        }
        $userId = (int) get_current_user_id();
        if (!current_user_can(\IndianOpinion\TranslateSidecar\Plugin::capability('review'))) {
            return null;
        }
        $rowId = Preview::validate($query, $userId, time());
        if ($rowId === null) {
            return null;
        }
        $row = $this->store->find($rowId);
        if ($row === null || $row->postId !== $normal->postId || $row->lang !== $lang || !in_array($row->state, [Row::CANDIDATE, Row::LIVE], true)) {
            return null;
        }

        return new Decision(200, $lang, $normal->postId, null, $row, 'preview', 'private, no-store');
    }

    public function isPreview(): bool
    {
        return $this->decision !== null && $this->decision->why === 'preview';
    }

    private function trashed(\WP_Post $post, string $lang): Decision
    {
        $rows = $this->store->rowsFor((int) $post->ID, $lang, [Row::LIVE, Row::WITHDRAWN]);

        return $rows !== []
            ? new Decision(410, $lang, (int) $post->ID, null, null, 'trashed')
            : new Decision(404, $lang, (int) $post->ID, null, null, 'trashed_never_live');
    }

    private static function trashedPath(string $path): string
    {
        $parts = explode('/', $path);
        $last = array_pop($parts);

        return implode('/', array_merge($parts, [$last . '__trashed']));
    }

    /**
     * @param list<string> $types
     */
    private function findPost(string $path, array $types): ?\WP_Post
    {
        if ($types === []) {
            return null;
        }
        $post = get_page_by_path($path, OBJECT, $types);

        return $post instanceof \WP_Post ? $post : null;
    }

    /**
     * @param list<string> $types
     */
    private function findByOldSlug(string $path, array $types): ?\WP_Post
    {
        $parts = explode('/', $path);
        $slug = (string) end($parts);
        $found = get_posts([
            'post_type' => $types,
            'post_status' => 'any',
            'meta_key' => '_wp_old_slug',
            'meta_value' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
            'suppress_filters' => true,
        ]);

        return isset($found[0]) && $found[0] instanceof \WP_Post ? $found[0] : null;
    }

    /**
     * The decision for the current request, once the request was parsed.
     */
    public function decision(): ?Decision
    {
        return $this->decision;
    }

    public function setDecision(?Decision $decision): void
    {
        $this->decision = $decision;
    }

    /**
     * The decision when the current request is a translation page, or null.
     * Returns null before the main query exists (case 94).
     */
    public function current(): ?Decision
    {
        if (!did_action('wp')) {
            return null;
        }

        return $this->decision !== null && $this->decision->isTranslation() ? $this->decision : null;
    }

    public function currentLanguage(): string
    {
        return $this->current()->lang ?? '';
    }

    /**
     * Sends the redirect or status for a non-200 decision. Returns true when
     * the response is complete and the caller should exit.
     */
    public function respond(): bool
    {
        $d = $this->decision;
        if ($d === null || $d->status === 200) {
            return false;
        }
        if ($d->cacheControl !== null && !headers_sent()) {
            header('Cache-Control: ' . $d->cacheControl);
        }
        if ($d->isRedirect()) {
            wp_safe_redirect((string) $d->location, $d->status, 'translate-sidecar');

            return true;
        }
        if ($d->status === 410) {
            status_header(410);
            nocache_headers();
        }

        return false;
    }

    /**
     * Stops core canonical redirects from sending /xx/slug/ to the
     * original's permalink.
     */
    public function filterRedirectCanonical(mixed $redirect): mixed
    {
        return $this->decision !== null ? false : $redirect;
    }
}
