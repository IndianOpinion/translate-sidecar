<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Front;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Log;
use IndianOpinion\TranslateSidecar\Support\Text;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * Value swaps on a translation page, scoped strictly to the queried post
 * (cases 27 to 31, 63). Every hook returns the unmodified value on any
 * error, so the original page is never broken.
 */
final class Frontend
{
    public const DESCRIPTION_LENGTH = 155;

    public function __construct(
        private readonly Router $router,
        private readonly Translations $translations,
        private readonly Registry $registry,
        private readonly Posts $posts,
        private readonly Settings $settings,
        private readonly Store $store,
    ) {
    }

    public function register(): void
    {
        add_filter('the_title', [$this, 'title'], 10, 2);
        add_filter('the_content', [$this, 'content'], 1);
        add_filter('get_the_excerpt', [$this, 'excerpt'], 10, 2);
        add_filter('language_attributes', [$this, 'languageAttributes'], 20, 2);
        add_filter('pre_get_document_title', [$this, 'documentTitle'], 20);
        add_filter('document_title_parts', [$this, 'documentTitleParts'], 20);
        add_filter('wp_get_attachment_image_attributes', [$this, 'imageAttributes'], 10, 2);
    }

    private function language(): ?Language
    {
        $d = $this->router->current();

        return $d !== null ? $this->registry->resolve($d->lang) : null;
    }

    /**
     * Swaps the title of the queried post only. Menus, widgets and related
     * lists keep their own titles.
     */
    public function title(mixed $title, mixed $id = 0): mixed
    {
        try {
            $d = $this->router->current();
            if ($d === null || (int) $id !== $d->postId || $d->row === null || $d->row->title === '' || is_admin()) {
                return $title;
            }

            return esc_html($d->row->title);
        } catch (\Throwable $e) {
            Log::error('the_title', $e);

            return $title;
        }
    }

    /**
     * Swaps the body early, so later filters work on the translated text.
     */
    public function content(mixed $content): mixed
    {
        try {
            $d = $this->router->current();
            if ($d === null || $d->row === null || (int) get_the_ID() !== $d->postId) {
                return $content;
            }

            return $this->rewriteLinks($d->row->content, $d->lang) . $this->originalLink($d->postId, $d->lang);
        } catch (\Throwable $e) {
            Log::error('the_content', $e);

            return $content;
        }
    }

    public function excerpt(mixed $excerpt, mixed $post = null): mixed
    {
        try {
            $d = $this->router->current();
            $id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();
            if ($d === null || $d->row === null || $id !== $d->postId) {
                return $excerpt;
            }
            if ($d->row->excerpt !== '') {
                return $d->row->excerpt;
            }

            return Text::trimGraphemes(Text::plain($d->row->content), 300);
        } catch (\Throwable $e) {
            Log::error('get_the_excerpt', $e);

            return $excerpt;
        }
    }

    public function languageAttributes(mixed $output, mixed $doctype = 'html'): mixed
    {
        try {
            $lang = $this->language();
            if ($lang === null || !is_string($output)) {
                return $output;
            }
            $output = (string) preg_replace('/\s*\b(lang|xml:lang|dir)="[^"]*"/', '', $output);
            $attrs = 'lang="' . esc_attr($lang->hreflang) . '" dir="' . esc_attr($lang->dir) . '"';

            return trim($attrs . ' ' . trim($output));
        } catch (\Throwable $e) {
            Log::error('language_attributes', $e);

            return $output;
        }
    }

    public function documentTitle(mixed $title): mixed
    {
        $d = $this->router->current();
        if ($d === null || $d->row === null || $d->row->seoTitle === '') {
            return $title;
        }

        return esc_html($d->row->seoTitle);
    }

    /**
     * @param mixed $parts
     * @return mixed
     */
    public function documentTitleParts(mixed $parts): mixed
    {
        $d = $this->router->current();
        if ($d === null || $d->row === null || !is_array($parts) || $d->row->title === '') {
            return $parts;
        }
        $parts['title'] = $d->row->title;

        return $parts;
    }

    /**
     * Translated alt text from the row's map for images outside the body,
     * such as the featured image.
     */
    public function imageAttributes(mixed $attr, mixed $attachment = null): mixed
    {
        $d = $this->router->current();
        if ($d === null || $d->row === null || !is_array($attr) || !$attachment instanceof \WP_Post) {
            return $attr;
        }
        $alt = $d->row->altText[(int) $attachment->ID] ?? '';
        if ($alt !== '') {
            $attr['alt'] = $alt;
        }

        return $attr;
    }

    /**
     * The translated title used for head tags: SEO title, else the title
     * and the site name.
     */
    public function seoTitle(): string
    {
        $d = $this->router->current();
        if ($d === null || $d->row === null) {
            return '';
        }
        if ($d->row->seoTitle !== '') {
            return $d->row->seoTitle;
        }

        return trim($d->row->title . ' - ' . (string) get_bloginfo('name'), ' -');
    }

    /**
     * Meta description: the stored one, or the excerpt or body trimmed to
     * 155 characters by grapheme.
     */
    public function description(): string
    {
        $d = $this->router->current();
        if ($d === null || $d->row === null) {
            return '';
        }
        if ($d->row->metaDescription !== '') {
            return $d->row->metaDescription;
        }
        $source = $d->row->excerpt !== '' ? $d->row->excerpt : Text::plain($d->row->content);

        return Text::trimGraphemes($source, self::DESCRIPTION_LENGTH);
    }

    /**
     * Rewrites links to other translatable posts to their translation in
     * the same language, when one is routed. One batched lookup of posts
     * and one of rows.
     */
    public function rewriteLinks(string $html, string $lang): string
    {
        $home = rtrim((string) home_url('/'), '/');
        if (preg_match_all('/<a\s[^>]*?href=(["\'])([^"\']+)\1/i', $html, $m) === 0) {
            return $html;
        }
        $candidates = [];
        foreach (array_unique($m[2]) as $href) {
            $href = (string) $href;
            if (!str_starts_with($href, $home . '/')) {
                continue;
            }
            $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
            $homePath = trim((string) parse_url($home, PHP_URL_PATH), '/');
            if ($homePath !== '' && str_starts_with($path, $homePath)) {
                $path = trim(substr($path, strlen($homePath)), '/');
            }
            if ($path === '') {
                continue;
            }
            $segments = explode('/', $path);
            $candidates[$href] = (string) end($segments);
        }
        if ($candidates === []) {
            return $html;
        }
        $posts = get_posts([
            'post_type' => $this->settings->postTypes(),
            'post_status' => 'publish',
            'post_name__in' => array_values(array_unique($candidates)),
            'posts_per_page' => count($candidates),
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);
        $byUrl = [];
        foreach ($posts as $p) {
            if ($p instanceof \WP_Post) {
                $byUrl[self::normaliseUrl((string) get_permalink($p))] = $p;
            }
        }
        $matched = [];
        foreach (array_keys($candidates) as $href) {
            $key = self::normaliseUrl((string) $href);
            if (isset($byUrl[$key])) {
                $matched[$href] = $byUrl[$key];
            }
        }
        if ($matched === []) {
            return $html;
        }
        $ids = array_values(array_unique(array_map(static fn (\WP_Post $p): int => (int) $p->ID, $matched)));
        $this->translations->prime($lang, $this->store->liveForPosts($ids, $lang));
        $replace = [];
        foreach ($matched as $href => $p) {
            $url = $this->translations->url((int) $p->ID, $lang);
            if ($url !== null) {
                $suffix = '';
                $frag = parse_url((string) $href, PHP_URL_FRAGMENT);
                if (is_string($frag) && $frag !== '') {
                    $suffix = '#' . $frag;
                }
                $replace[$href] = $url . $suffix;
            }
        }
        if ($replace === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<a\s[^>]*?href=)(["\'])([^"\']+)\2/i',
            static fn (array $mm): string => isset($replace[$mm[3]])
                ? $mm[1] . $mm[2] . esc_url($replace[$mm[3]]) . $mm[2]
                : $mm[0],
            $html
        );
    }

    private static function normaliseUrl(string $url): string
    {
        $url = (string) preg_replace('/[#?].*$/', '', $url);

        return rtrim(strtolower($url), '/');
    }

    /**
     * The static "read the original" link, placed after the body. Not shown
     * when the original is in the page's language.
     */
    public function originalLink(int $postId, string $lang): string
    {
        $post = $this->posts->get($postId);
        $language = $this->registry->resolve($lang);
        if ($post === null || $language === null) {
            return '';
        }
        $sourceTag = $this->posts->sourceLanguage($post);
        if (Language::tagsMatch($sourceTag, $language->hreflang)) {
            return '';
        }
        $html = sprintf(
            '<p class="translate-sidecar-original"><a href="%s" hreflang="%s" lang="%s">%s</a></p>',
            esc_url((string) get_permalink($post)),
            esc_attr($sourceTag),
            esc_attr($sourceTag),
            esc_html($language->originalLinkLabel)
        );

        return (string) apply_filters('translate_sidecar_original_link', $html, $postId, $lang);
    }

    /**
     * Links to the routed translations of a post, for a language picker
     * (case 63). Empty when there are none.
     *
     * @param array{class?:string, show_original?:bool} $args
     */
    public function switcher(int $postId, array $args = []): string
    {
        if ($postId <= 0) {
            $postId = (int) get_the_ID();
        }
        $post = $this->posts->get($postId);
        if ($post === null) {
            return '';
        }
        $items = [];
        $current = $this->router->current()->lang ?? '';
        if (!empty($args['show_original']) && $current !== '') {
            $tag = $this->posts->sourceLanguage($post);
            $items[] = sprintf('<li><a href="%s" hreflang="%s" lang="%s">%s</a></li>', esc_url((string) get_permalink($post)), esc_attr($tag), esc_attr($tag), esc_html($tag));
        }
        foreach ($this->translations->routedLanguages($postId) as $prefix => $lang) {
            if ($prefix === $current) {
                continue;
            }
            $items[] = sprintf(
                '<li><a href="%s" hreflang="%s" lang="%s">%s</a></li>',
                esc_url($this->translations->buildUrl($post, $prefix)),
                esc_attr($lang->hreflang),
                esc_attr($lang->hreflang),
                esc_html($lang->nativeName)
            );
        }
        if ($items === []) {
            return '';
        }
        $class = isset($args['class']) ? (string) $args['class'] : 'translate-sidecar-switcher';

        return '<ul class="' . esc_attr($class) . '">' . implode('', $items) . '</ul>';
    }
}
