<?php

/**
 * Uninstall: drops the three tables, deletes every option and post meta key
 * with the plugin's prefix, clears scheduled events and flushes rewrite
 * rules. On multisite, for every site.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

\IndianOpinion\TranslateSidecar\Uninstaller::run();
