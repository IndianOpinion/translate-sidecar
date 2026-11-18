<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Segment\SourceFields;
use IndianOpinion\TranslateSidecar\Support\Text;

/**
 * Facts about original posts: source fields, source language, eligibility.
 */
final class Posts
{
    public const META_SOURCE_LANG = '_translate_sidecar_source_lang';
    public const META_SKIP = '_translate_sidecar_skip';

    public function __construct(
        private readonly Settings $settings,
        private readonly Registry $registry,
        private readonly ScriptCounter $counter,
    ) {
    }

    public function get(int $postId): ?\WP_Post
    {
        $post = get_post($postId);

        return $post instanceof \WP_Post ? $post : null;
    }

    public function sourceFields(\WP_Post $post): SourceFields
    {
        $fields = [
            'title' => (string) $post->post_title,
            'content' => (string) $post->post_content,
            'excerpt' => (string) $post->post_excerpt,
            'seo_title' => '',
            'meta_description' => '',
        ];
        $filtered = apply_filters('translate_sidecar_source_fields', $fields, $post);
        if (is_array($filtered)) {
            $fields = array_merge($fields, array_map('strval', array_intersect_key($filtered, $fields)));
        }

        return new SourceFields($fields['title'], $fields['content'], $fields['excerpt'], $fields['seo_title'], $fields['meta_description']);
    }

    public function segmenter(): Segmenter
    {
        global $shortcode_tags;
        $verbatim = (array) apply_filters('translate_sidecar_verbatim_blocks', [
            'core/code', 'core/preformatted', 'core/shortcode', 'core/embed',
        ]);

        return new Segmenter(
            array_values(array_map('strval', $verbatim)),
            is_array($shortcode_tags) ? array_map('strval', array_keys($shortcode_tags)) : [],
            $this->settings->bool('translate_alt'),
        );
    }

    /**
     * Source language tag of a post: its own meta, or the site's.
     */
    public function sourceLanguage(\WP_Post $post): string
    {
        $meta = (string) get_post_meta((int) $post->ID, self::META_SOURCE_LANG, true);

        return $meta !== '' ? $meta : $this->settings->sourceLanguage();
    }

    /**
     * The registered language a post is natively written in, if any.
     */
    public function nativeLanguage(\WP_Post $post): ?Language
    {
        $meta = (string) get_post_meta((int) $post->ID, self::META_SOURCE_LANG, true);
        if ($meta === '') {
            return null;
        }

        return $this->registry->byHreflang($meta) ?? $this->registry->get($meta);
    }

    /**
     * Open Graph locale of the original.
     */
    public function sourceLocale(\WP_Post $post): string
    {
        $native = $this->nativeLanguage($post);

        return $native !== null ? $native->locale : $this->settings->sourceLocale();
    }

    public function isTranslatableType(string $type): bool
    {
        return in_array($type, $this->settings->postTypes(), true);
    }

    public function isPubliclyViewable(\WP_Post $post): bool
    {
        return $post->post_status === 'publish' && (string) $post->post_password === '';
    }

    public function isExcluded(\WP_Post $post): bool
    {
        return (bool) get_post_meta((int) $post->ID, self::META_SKIP, true);
    }

    /**
     * Why a post may not be sent for translation into a language, or null
     * when it may.
     */
    public function refusal(\WP_Post $post, Language $lang): ?string
    {
        if (!$this->isTranslatableType((string) $post->post_type)) {
            return 'not_eligible';
        }
        if (!$this->isPubliclyViewable($post)) {
            return 'not_eligible';
        }
        if (apply_filters('translate_sidecar_is_eligible', true, $post, $lang->prefix) === false) {
            return 'not_eligible';
        }
        if ($this->isExcluded($post)) {
            return 'excluded';
        }
        if (Language::tagsMatch($this->sourceLanguage($post), $lang->hreflang)) {
            return 'same_language';
        }
        $native = $this->nativeLanguage($post);
        if ($native !== null && $native->prefix === $lang->prefix) {
            return 'same_language';
        }
        $min = $this->settings->int('min_words');
        if ($this->wordCount($post) < $min) {
            return 'too_short';
        }

        return null;
    }

    public function wordCount(\WP_Post $post): int
    {
        $content = (string) $post->post_content;
        if (!Segmenter::hasBlocks($content) && function_exists('wpautop')) {
            $content = wpautop($content);
        }

        return Text::wordCount(Text::plain($content), $this->counter);
    }
}
