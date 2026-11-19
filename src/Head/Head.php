<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Head;

use IndianOpinion\TranslateSidecar\Front\Frontend;
use IndianOpinion\TranslateSidecar\Front\Router;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Support\Log;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * Canonical, hreflang, Open Graph and robots output (cases 35, 64 to 69).
 */
final class Head
{
    private bool $buffering = false;
    private ?string $mode = null;

    public function __construct(
        private readonly Router $router,
        private readonly Translations $translations,
        private readonly Registry $registry,
        private readonly Posts $posts,
        private readonly Settings $settings,
        private readonly Frontend $frontend,
        private readonly StructuredData $schema,
    ) {
    }

    public function register(): void
    {
        add_action('wp', [$this, 'setup'], 20);
        add_filter('wp_robots', [$this, 'robots'], 50);
        add_filter('wp_headers', [$this, 'headers'], 20);
    }

    /**
     * The active head mode. "auto" means "filters" when another plugin
     * prints a canonical, else "core".
     */
    public function mode(): string
    {
        if ($this->mode !== null) {
            return $this->mode;
        }
        $mode = (string) $this->settings->get('head_mode');
        if ($mode === 'auto' || !in_array($mode, ['core', 'filters', 'rewrite'], true)) {
            $mode = self::detectSeoPlugin() ? 'filters' : 'core';
        }

        return $this->mode = $mode;
    }

    public function resetMode(): void
    {
        $this->mode = null;
    }

    /**
     * Detects an SEO plugin by inspecting wp_head callbacks, without naming
     * any plugin: either core's rel_canonical was removed by someone, or a
     * callback from outside WordPress core and this plugin has "canonical",
     * "opengraph" or "seo" in its function, method or class name.
     */
    public static function detectSeoPlugin(): bool
    {
        global $wp_filter;
        if (!isset($wp_filter['wp_head'])) {
            return false;
        }
        $hook = $wp_filter['wp_head'];
        $callbacks = is_object($hook) && isset($hook->callbacks) ? $hook->callbacks : (is_array($hook) ? $hook : []);
        $coreCanonical = false;
        foreach ($callbacks as $list) {
            foreach ((array) $list as $entry) {
                $fn = is_array($entry) && isset($entry['function']) ? $entry['function'] : null;
                if ($fn === 'rel_canonical') {
                    $coreCanonical = true;
                    continue;
                }
                $name = self::callableName($fn);
                if ($name === '' || str_contains($name, 'IndianOpinion\\TranslateSidecar')) {
                    continue;
                }
                if (preg_match('/canonical|opengraph|open_graph|\bseo|seo_|_seo|Seo/i', $name) === 1) {
                    return true;
                }
            }
        }

        return !$coreCanonical && did_action('init') > 0 && function_exists('rel_canonical');
    }

    private static function callableName(mixed $fn): string
    {
        if (is_string($fn)) {
            return $fn;
        }
        if (is_array($fn) && count($fn) === 2) {
            $class = is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0];

            return $class . '::' . (string) $fn[1];
        }
        if ($fn instanceof \Closure) {
            return '';
        }
        if (is_object($fn)) {
            return get_class($fn);
        }

        return '';
    }

    /**
     * The queried original post when it is translatable and public.
     */
    private function originalId(): int
    {
        if ($this->router->current() !== null || !is_singular()) {
            return 0;
        }
        $id = (int) get_queried_object_id();
        $post = $this->posts->get($id);
        if ($post === null || !$this->posts->isTranslatableType((string) $post->post_type)) {
            return 0;
        }

        return $id;
    }

    public function setup(): void
    {
        $d = $this->router->current();
        $originalId = $this->originalId();
        if ($d === null && $originalId === 0) {
            return;
        }
        $mode = $this->mode();
        if ($mode === 'rewrite') {
            add_action('wp_head', [$this, 'startBuffer'], -10000);
            add_action('wp_head', [$this, 'endBuffer'], PHP_INT_MAX);

            return;
        }
        if ($mode === 'core' && $d !== null) {
            remove_action('wp_head', 'rel_canonical');
        }
        add_action('wp_head', [$this, 'printHead'], 1);
    }

    /**
     * Values describing a translation page.
     *
     * @return array{canonical:string, og_url:string, og_locale:string, og_locale_alternate:list<string>, title:string, description:string, robots:string, lang:string}
     */
    public function values(int $postId, string $lang): array
    {
        $post = $this->posts->get($postId);
        $language = $this->registry->resolve($lang);
        if ($post === null || $language === null) {
            throw new \InvalidArgumentException('Unknown post or language.');
        }
        $own = $this->translations->buildUrl($post, $lang);
        $canonical = (string) apply_filters('translate_sidecar_canonical', $own, $postId, $lang);
        $alternates = [];
        if ($language->isIndexable()) {
            foreach (array_keys($this->translations->cluster($postId)) as $tag) {
                if ($tag === 'x-default' || strtolower($tag) === strtolower($language->hreflang)) {
                    continue;
                }
                $member = $this->registry->byHreflang($tag);
                $alternates[] = $member !== null ? $member->locale : str_replace('-', '_', $tag);
            }
        }
        $values = [
            'canonical' => $canonical,
            'og_url' => $own,
            'og_locale' => $language->locale,
            'og_locale_alternate' => array_values(array_unique($alternates)),
            'title' => $this->frontend->seoTitle(),
            'description' => $this->frontend->description(),
            'robots' => $language->isIndexable() ? '' : 'noindex, follow',
            'lang' => $language->hreflang,
        ];
        $filtered = apply_filters('translate_sidecar_head_values', $values, $postId, $lang);

        /** @var array{canonical:string, og_url:string, og_locale:string, og_locale_alternate:list<string>, title:string, description:string, robots:string, lang:string} */
        return is_array($filtered) ? array_merge($values, $filtered) : $values;
    }

    /**
     * The hreflang link elements for a post, or an empty string.
     */
    public function hreflangLinks(int $postId): string
    {
        $out = '';
        foreach ($this->translations->cluster($postId) as $tag => $url) {
            $out .= sprintf('<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr($tag), esc_url($url));
        }

        return $out;
    }

    /**
     * Prints the head block for the active mode. Hreflang always; in core
     * mode also canonical, Open Graph and JSON-LD on translation pages.
     */
    public function printHead(): void
    {
        try {
            echo $this->headHtml($this->mode()); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
        } catch (\Throwable $e) {
            Log::error('wp_head', $e);
        }
    }

    public function headHtml(string $mode): string
    {
        $d = $this->router->current();
        if ($d !== null) {
            $language = $this->registry->resolve($d->lang);
            $html = ($language !== null && $language->isIndexable()) ? $this->hreflangLinks($d->postId) : '';
            if ($mode === 'core') {
                $v = $this->values($d->postId, $d->lang);
                $html .= sprintf('<link rel="canonical" href="%s" />' . "\n", esc_url($v['canonical']));
                $html .= sprintf('<meta name="description" content="%s" />' . "\n", esc_attr($v['description']));
                $html .= sprintf('<meta property="og:locale" content="%s" />' . "\n", esc_attr($v['og_locale']));
                foreach ($v['og_locale_alternate'] as $alt) {
                    $html .= sprintf('<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr($alt));
                }
                $html .= sprintf('<meta property="og:type" content="article" />' . "\n");
                $html .= sprintf('<meta property="og:url" content="%s" />' . "\n", esc_url($v['og_url']));
                $html .= sprintf('<meta property="og:title" content="%s" />' . "\n", esc_attr($v['title']));
                $html .= sprintf('<meta property="og:description" content="%s" />' . "\n", esc_attr($v['description']));
                $html .= sprintf('<meta name="twitter:title" content="%s" />' . "\n", esc_attr($v['title']));
                $html .= sprintf('<meta name="twitter:description" content="%s" />' . "\n", esc_attr($v['description']));
                $html .= $this->schema->script($d->postId, $d->lang);
            }

            return $html;
        }
        $originalId = $this->originalId();
        if ($originalId === 0) {
            return '';
        }
        $html = $this->hreflangLinks($originalId);
        if ($mode === 'core' && $html !== '') {
            $html .= $this->schema->script($originalId, null);
        }

        return $html;
    }

    public function startBuffer(): void
    {
        if (!$this->buffering) {
            $this->buffering = ob_start();
        }
    }

    public function endBuffer(): void
    {
        if (!$this->buffering) {
            return;
        }
        $this->buffering = false;
        $html = (string) ob_get_clean();
        try {
            echo $this->rewrite($html); // phpcs:ignore WordPress.Security.EscapeOutput -- rewrites escaped head output.
        } catch (\Throwable $e) {
            Log::error('head rewrite', $e);
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- original head output.
        }
    }

    /**
     * Rewrite mode: edits another plugin's head output in place. Removes
     * only link elements that carry hreflang; feed and oEmbed alternates are
     * left alone. Then appends this plugin's hreflang links.
     */
    public function rewrite(string $html): string
    {
        $html = (string) preg_replace('/<link\b[^>]*\bhreflang\s*=[^>]*>\s*/i', '', $html);
        $d = $this->router->current();
        if ($d === null) {
            $id = $this->originalId();

            return $html . ($id > 0 ? $this->hreflangLinks($id) : '');
        }
        $v = $this->values($d->postId, $d->lang);
        $attr = static fn (string $s): string => esc_attr($s);
        $html = (string) preg_replace_callback(
            '/(<link\b[^>]*\brel=["\']canonical["\'][^>]*\bhref=["\'])([^"\']*)(["\'])/i',
            static fn (array $m): string => $m[1] . esc_url($v['canonical']) . $m[3],
            $html
        );
        $html = (string) preg_replace_callback(
            '/(<link\b[^>]*\bhref=["\'])([^"\']*)(["\'][^>]*\brel=["\']canonical["\'])/i',
            static fn (array $m): string => $m[1] . esc_url($v['canonical']) . $m[3],
            $html
        );
        $metas = [
            'og:url' => esc_url($v['og_url']),
            'og:locale' => $attr($v['og_locale']),
            'og:title' => $attr($v['title']),
            'og:description' => $attr($v['description']),
            'twitter:title' => $attr($v['title']),
            'twitter:description' => $attr($v['description']),
            'description' => $attr($v['description']),
        ];
        foreach ($metas as $key => $value) {
            $q = preg_quote($key, '/');
            $html = (string) preg_replace_callback(
                '/(<meta\b[^>]*\b(?:property|name)=["\']' . $q . '["\'][^>]*\bcontent=["\'])([^"\']*)(["\'])/i',
                static fn (array $m): string => $m[1] . $value . $m[3],
                $html
            );
            $html = (string) preg_replace_callback(
                '/(<meta\b[^>]*\bcontent=["\'])([^"\']*)(["\'][^>]*\b(?:property|name)=["\']' . $q . '["\'])/i',
                static fn (array $m): string => $m[1] . $value . $m[3],
                $html
            );
        }
        $html = (string) preg_replace('/<meta\b[^>]*\bproperty=["\']og:locale:alternate["\'][^>]*>\s*/i', '', $html);
        $alts = '';
        foreach ($v['og_locale_alternate'] as $alt) {
            $alts .= sprintf('<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr($alt));
        }
        $html = (string) preg_replace_callback(
            '/<title>[^<]*<\/title>/i',
            static fn (): string => '<title>' . esc_html($v['title']) . '</title>',
            $html
        );
        $language = $this->registry->resolve($d->lang);
        $links = ($language !== null && $language->isIndexable()) ? $this->hreflangLinks($d->postId) : '';

        return $html . $alts . $links;
    }

    /**
     * noindex, follow on translation pages of languages with index off.
     *
     * @param mixed $robots
     * @return mixed
     */
    public function robots(mixed $robots): mixed
    {
        $d = $this->router->current();
        if ($d === null || !is_array($robots)) {
            return $robots;
        }
        if ($this->router->isPreview()) {
            unset($robots['index'], $robots['follow']);
            $robots['noindex'] = true;
            $robots['nofollow'] = true;

            return $robots;
        }
        $language = $this->registry->resolve($d->lang);
        if ($language !== null && !$language->isIndexable()) {
            unset($robots['index'], $robots['nofollow']);
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }

        return $robots;
    }

    /**
     * @param mixed $headers
     * @return mixed
     */
    public function headers(mixed $headers): mixed
    {
        if (!is_array($headers)) {
            return $headers;
        }
        $d = $this->router->decision();
        if ($d === null || !$d->isTranslation()) {
            return $headers;
        }
        if ($this->router->isPreview()) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
            $headers['Cache-Control'] = 'private, no-store';

            return $headers;
        }
        $language = $this->registry->resolve($d->lang);
        if ($language !== null && !$language->isIndexable()) {
            $headers['X-Robots-Tag'] = 'noindex, follow';
        }

        return $headers;
    }
}
