<?php

/**
 * Plugin Name:       translate-sidecar
 * Plugin URI:        https://github.com/IndianOpinion/translate-sidecar
 * Description:       Keeps post translations in their own table, routes /xx/slug/ to them, shares images and schema with the original, emits hreflang and canonical tags, and builds a sitemap per language. Every translation is held for review.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       translate-sidecar
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/src/autoload.php';

register_activation_hook(__FILE__, [\IndianOpinion\TranslateSidecar\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\IndianOpinion\TranslateSidecar\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \IndianOpinion\TranslateSidecar\Plugin::instance()->boot();
});
