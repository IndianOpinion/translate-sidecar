<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Head;

use IndianOpinion\TranslateSidecar\Front\Frontend;
use IndianOpinion\TranslateSidecar\Front\Router;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Translations;

/**
 * JSON-LD for translations (case 70). Images, author, publisher and dates
 * stay the original's; language, headline, description and URL describe
 * the translation; translationOfWork points at the original.
 */
final class StructuredData
{
    private const ARTICLE_TYPES = ['Article', 'NewsArticle', 'BlogPosting', 'Report', 'ScholarlyArticle', 'TechArticle'];
    private const PAGE_TYPES = ['WebPage', 'ItemPage', 'AboutPage', 'CollectionPage'];

    public function __construct(
        private readonly Router $router,
        private readonly Translations $translations,
        private readonly Registry $registry,
        private readonly Posts $posts,
        private readonly Frontend $frontend,
    ) {
    }

    public function register(): void
    {
        add_filter('translate_sidecar_schema_graph', [$this, 'filterGraph'], 10, 3);
    }

    /**
     * Callback for translate_sidecar_schema_graph.
     *
     * @param mixed $graph
     * @return mixed
     */
    public function filterGraph(mixed $graph, mixed $postId = 0, mixed $lang = ''): mixed
    {
        if (!is_array($graph)) {
            return $graph;
        }
        try {
            return $this->adjust($graph, (int) $postId, (string) $lang);
        } catch (\Throwable $e) {
            \IndianOpinion\TranslateSidecar\Support\Log::error('schema graph', $e);

            return $graph;
        }
    }

    private function articleId(string $url): string
    {
        return $url . '#article';
    }

    /**
     * Adjusts a graph: a single node, a list of nodes, or an object with
     * an @graph list. With an empty $lang the post is the original and
     * workTranslation is added.
     *
     * @param array<mixed> $graph
     * @return array<mixed>
     */
    public function adjust(array $graph, int $postId, string $lang): array
    {
        $post = $this->posts->get($postId);
        if ($post === null) {
            return $graph;
        }
        $original = (string) get_permalink($post);
        if (isset($graph['@graph']) && is_array($graph['@graph'])) {
            $graph['@graph'] = array_map(fn ($n) => is_array($n) ? $this->node($n, $post, $original, $lang) : $n, $graph['@graph']);

            return $graph;
        }
        if (array_is_list($graph)) {
            return array_map(fn ($n) => is_array($n) ? $this->node($n, $post, $original, $lang) : $n, $graph);
        }

        return $this->node($graph, $post, $original, $lang);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private function node(array $node, \WP_Post $post, string $original, string $lang): array
    {
        $types = (array) ($node['@type'] ?? []);
        $isArticle = array_intersect($types, self::ARTICLE_TYPES) !== [];
        $isPage = array_intersect($types, self::PAGE_TYPES) !== [];
        if (!$isArticle && !$isPage) {
            return $node;
        }
        if ($lang === '') {
            if ($isArticle) {
                $works = [];
                foreach ($this->translations->routedLanguages((int) $post->ID) as $prefix => $language) {
                    if ($language->isIndexable()) {
                        $works[] = ['@id' => $this->articleId($this->translations->buildUrl($post, $prefix))];
                    }
                }
                if ($works !== []) {
                    $node['workTranslation'] = $works;
                }
            }

            return $node;
        }
        $language = $this->registry->resolve($lang);
        $d = $this->router->current();
        if ($language === null) {
            return $node;
        }
        $url = $this->translations->buildUrl($post, $lang);
        $originalArticleId = isset($node['@id']) && is_string($node['@id']) ? $node['@id'] : $this->articleId($original);
        $node['inLanguage'] = $language->hreflang;
        if ($isArticle) {
            $title = $d !== null && $d->row !== null ? $d->row->title : '';
            if ($title !== '') {
                $node['headline'] = $title;
            }
            $node['description'] = $this->frontend->description();
            $node['@id'] = $this->articleId($url);
            $node['url'] = $url;
            $node['mainEntityOfPage'] = ['@id' => $url];
            $node['translationOfWork'] = ['@id' => $originalArticleId];
        } else {
            $node['@id'] = $url;
            $node['url'] = $url;
            $node['name'] = $this->frontend->seoTitle();
        }

        return $node;
    }

    /**
     * The plugin's own minimal graph in core mode.
     *
     * @return array<string, mixed>
     */
    public function coreGraph(int $postId, ?string $lang): array
    {
        $post = $this->posts->get($postId);
        if ($post === null) {
            return [];
        }
        $original = (string) get_permalink($post);
        $node = [
            '@type' => 'Article',
            '@id' => $this->articleId($original),
            'headline' => (string) get_the_title($post),
            'url' => $original,
            'mainEntityOfPage' => ['@id' => $original],
            'inLanguage' => $this->posts->sourceLanguage($post),
            'datePublished' => (string) get_post_time('c', true, $post),
            'dateModified' => (string) get_post_modified_time('c', true, $post),
            'author' => ['@type' => 'Person', 'name' => (string) get_the_author_meta('display_name', (int) $post->post_author)],
        ];
        $image = get_the_post_thumbnail_url($post, 'full');
        if (is_string($image) && $image !== '') {
            $node['image'] = [$image];
        }
        $graph = ['@context' => 'https://schema.org', '@graph' => [$node]];

        return $this->adjust($graph, $postId, $lang ?? '');
    }

    public function script(int $postId, ?string $lang): string
    {
        $graph = $this->coreGraph($postId, $lang);
        if ($graph === []) {
            return '';
        }
        $json = wp_json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        return is_string($json) ? '<script type="application/ld+json">' . $json . "</script>\n" : '';
    }
}
