<?php

/**
 * Plugin Name: translate-sidecar caller (example)
 * Description: Supplies a model caller through the WordPress HTTP API.
 *
 * Copy to wp-content/mu-plugins/ and adapt. The endpoint, key and model come
 * from constants in wp-config.php, never from an option:
 *
 *   define('TRANSLATE_SIDECAR_LLM_ENDPOINT', 'https://llm.example/v1/chat/completions');
 *   define('TRANSLATE_SIDECAR_LLM_KEY', '...');
 *   define('TRANSLATE_SIDECAR_LLM_MODEL', 'your-model');
 *
 * Then choose "Site caller" as the adapter on the settings screen. The
 * request body below follows the common chat-completions shape; change it
 * to whatever your provider documents. indianopinion/llm-json-php can do
 * the same job with retries and JSON repair.
 */

declare(strict_types=1);

use IndianOpinion\TranslateSidecar\Translator\TranslatorException;

add_filter('translate_sidecar_llm_caller', static function () {
    if (!defined('TRANSLATE_SIDECAR_LLM_ENDPOINT') || !defined('TRANSLATE_SIDECAR_LLM_KEY')) {
        return null;
    }

    return static function (string $system, string $user, array $schema, array $meta): array {
        $response = wp_remote_post(constant('TRANSLATE_SIDECAR_LLM_ENDPOINT'), [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . constant('TRANSLATE_SIDECAR_LLM_KEY'),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => defined('TRANSLATE_SIDECAR_LLM_MODEL') ? constant('TRANSLATE_SIDECAR_LLM_MODEL') : '',
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'translation', 'strict' => true, 'schema' => $schema]],
            ]),
        ]);
        if (is_wp_error($response)) {
            throw TranslatorException::retryable($response->get_error_message(), 'transport');
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            throw TranslatorException::fromHttpStatus($status);
        }
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $choice = $body['choices'][0] ?? [];
        $data = json_decode((string) ($choice['message']['content'] ?? ''), true);
        if (!is_array($data)) {
            throw TranslatorException::fatal('Reply is not JSON.', 'bad_shape');
        }
        $data['_meta'] = [
            'finish_reason' => ($choice['finish_reason'] ?? 'stop') === 'length' ? 'length' : 'stop',
            'provider' => (string) wp_parse_url(constant('TRANSLATE_SIDECAR_LLM_ENDPOINT'), PHP_URL_HOST),
            'model' => (string) ($body['model'] ?? ''),
            'usage' => [
                'input_units' => (int) ($body['usage']['prompt_tokens'] ?? 0),
                'output_units' => (int) ($body['usage']['completion_tokens'] ?? 0),
            ],
        ];

        return $data;
    };
});
