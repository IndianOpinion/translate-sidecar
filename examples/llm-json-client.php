<?php

/**
 * Plugin Name: translate-sidecar llm-json-php client (example)
 * Description: Hands translate-sidecar its own llm-json-php client.
 *
 * Copy to wp-content/mu-plugins/ and adapt. Most sites need none of this:
 * defining TRANSLATE_SIDECAR_LLM_KEY and TRANSLATE_SIDECAR_LLM_MODEL in
 * wp-config.php is enough. Use the filter when you want your own chain,
 * a store that survives across requests on a host without an object
 * cache, or a logger. The key is read from a constant, never an option.
 *
 *   define('EXAMPLE_OPENROUTER_KEY', '...');
 *
 * Then choose "Language model (llm-json-php)" as the adapter on the
 * settings screen.
 */

declare(strict_types=1);

use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

add_filter('translate_sidecar_llm_client', static function ($client) {
    if (!defined('EXAMPLE_OPENROUTER_KEY') || !class_exists(Client::class)) {
        return $client;
    }
    $openRouter = Provider::openRouter((string) constant('EXAMPLE_OPENROUTER_KEY'));

    return Client::create(
        Chain::of(
            Route::to($openRouter, 'your/primary-model'),
            Route::to($openRouter, 'your/fallback-model'),
        ),
        new WordPressTransport()
    )
        ->withStore(new WordPressTransientStore())
        // One attempt per model: translate-sidecar has its own backoff.
        ->withOptions(Options::defaults()->withAttemptsPerRoute(1)->withDeadline(120));
});
