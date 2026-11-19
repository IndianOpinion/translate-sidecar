<?php

/**
 * Plugin Name: translate-sidecar (must-use loader)
 * Description: Loads translate-sidecar from wp-content/mu-plugins/translate-sidecar/.
 *
 * Not supported in 0.1.0: must-use plugins have no activation or uninstall
 * hooks. The tables are created on the first admin request (the plugin
 * installs its schema whenever the recorded version is missing), and
 * uninstall.php never runs, so drop the tables yourself if you remove it.
 * Copy this file to wp-content/mu-plugins/translate-sidecar-loader.php.
 */

declare(strict_types=1);

$translateSidecar = __DIR__ . '/translate-sidecar/translate-sidecar.php';
if (is_file($translateSidecar)) {
    require_once $translateSidecar;
}
unset($translateSidecar);
