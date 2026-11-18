<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Storage\Schema;

/**
 * Removes everything the plugin created.
 */
final class Uninstaller
{
    public static function run(): void
    {
        if (is_multisite() && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site();
                restore_current_blog();
            }
            delete_site_option(Settings::NETWORK_KILL_OPTION);

            return;
        }
        self::site();
    }

    public static function site(): void
    {
        global $wpdb;
        (new Schema($wpdb))->drop();
        $like = $wpdb->esc_like('translate_sidecar_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        $metaLike = $wpdb->esc_like('_translate_sidecar_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $metaLike));
        wp_clear_scheduled_hook(Plugin::TICK_HOOK);
        wp_clear_scheduled_hook(Plugin::DAILY_HOOK);
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        delete_option('rewrite_rules');
    }
}
