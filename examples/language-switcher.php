<?php

/**
 * Theme snippet: a language picker after the post content.
 *
 * Prints nothing when the post has no routed translations. The block
 * "translate-sidecar/language-switcher" does the same in block themes.
 */

declare(strict_types=1);

add_filter('the_content', static function (string $content): string {
    if (!is_singular() || !function_exists('translate_sidecar_language_switcher')) {
        return $content;
    }

    return $content . translate_sidecar_language_switcher(get_the_ID(), ['show_original' => true]);
}, 20);
