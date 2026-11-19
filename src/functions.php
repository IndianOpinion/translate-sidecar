<?php

/**
 * Public functions. The names and signatures are the contract.
 */

declare(strict_types=1);

use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Job\RunSummary;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\Row;

if (!function_exists('translate_sidecar_request')) {
    /**
     * Requests translations of a post into one or more languages.
     *
     * @param list<string> $langs
     * @param array{replace?:bool, dry_run?:bool, source?:string} $args
     * @return array<string, array<string, mixed>> result per language
     */
    function translate_sidecar_request(int $post_id, array $langs, array $args = []): array
    {
        if (!Plugin::can('request')) {
            $out = [];
            foreach ($langs as $lang) {
                $out[(string) $lang] = ['status' => 'refused', 'reason' => 'forbidden'];
            }

            return $out;
        }
        $args['user_id'] = get_current_user_id();

        return Plugin::instance()->requester->request($post_id, array_values(array_map('strval', $langs)), $args);
    }

    /**
     * A translation row of a post in a language. Live rows are cached per
     * request.
     */
    function translate_sidecar_get(int $post_id, string $lang, string $state = 'live'): ?Row
    {
        $plugin = Plugin::instance();
        if ($state === Row::LIVE) {
            return $plugin->translations->live($post_id, $lang);
        }
        $rows = $plugin->store->rowsFor($post_id, $lang, [$state]);

        return $rows[0] ?? null;
    }

    /**
     * URL of the translation, or null unless it is routed.
     */
    function translate_sidecar_url(int $post_id, string $lang): ?string
    {
        return Plugin::instance()->translations->url($post_id, $lang);
    }

    /**
     * The hreflang cluster: tag to URL. Empty when only the original.
     *
     * @return array<string, string>
     */
    function translate_sidecar_cluster(int $post_id): array
    {
        return Plugin::instance()->translations->cluster($post_id);
    }

    /**
     * The language prefix of the current translation page, or an empty
     * string. Empty before the main query exists.
     */
    function translate_sidecar_current_language(): string
    {
        return Plugin::instance()->router->currentLanguage();
    }

    /**
     * Registry entries.
     *
     * @return array<string, array<string, mixed>>
     */
    function translate_sidecar_languages(bool $public_only = false): array
    {
        $registry = Plugin::instance()->registry;
        $langs = $public_only ? $registry->published() : $registry->all();

        return array_map(static fn (Language $l): array => $l->toArray(), $langs);
    }

    /**
     * @param array{with_warnings?:bool, reason?:string} $args
     */
    function translate_sidecar_approve(int $row_id, array $args = []): bool|WP_Error
    {
        if (!Plugin::can('review')) {
            return new WP_Error('forbidden', 'You are not allowed to review translations.');
        }

        return Plugin::instance()->review->approve($row_id, get_current_user_id(), $args);
    }

    function translate_sidecar_reject(int $row_id, string $reason = ''): bool|WP_Error
    {
        if (!Plugin::can('review')) {
            return new WP_Error('forbidden', 'You are not allowed to review translations.');
        }

        return Plugin::instance()->review->reject($row_id, get_current_user_id(), $reason);
    }

    function translate_sidecar_withdraw(int $row_id): bool|WP_Error
    {
        if (!Plugin::can('review')) {
            return new WP_Error('forbidden', 'You are not allowed to review translations.');
        }

        return Plugin::instance()->review->withdraw($row_id, get_current_user_id());
    }

    function translate_sidecar_rollback(int $post_id, string $lang): bool|WP_Error
    {
        if (!Plugin::can('review')) {
            return new WP_Error('forbidden', 'You are not allowed to review translations.');
        }

        return Plugin::instance()->review->rollback($post_id, $lang, get_current_user_id());
    }

    /**
     * Runs one tick of the job queue.
     *
     * @param array{max?:int, time?:int, lang?:string} $opts
     */
    function translate_sidecar_run(array $opts = []): RunSummary
    {
        return Plugin::instance()->runner->tick($opts);
    }

    /**
     * Runs one queued job by id. For external queues.
     */
    function translate_sidecar_run_job(int $job_id): string
    {
        return Plugin::instance()->runner->runById($job_id);
    }

    /**
     * Pure guard for one field. $lang is a registered prefix or a BCP 47
     * tag; $field is title, body, excerpt, seo_title or meta_description.
     */
    function translate_sidecar_guard(string $source, string $translated, string $lang, string $field): GuardReport
    {
        $plugin = Plugin::instance();
        $language = $plugin->registry->resolve($lang) ?? $plugin->registry->byHreflang($lang)
            ?? Language::fromArray(['prefix' => strtolower($lang), 'hreflang' => $lang]);

        return $plugin->guards->make()->field($source, $translated, $language, $field, $plugin->guards->thresholds($language));
    }

    /**
     * Share of letters per script in a text.
     *
     * @return array<string, float>
     */
    function translate_sidecar_script_shares(string $text): array
    {
        return Plugin::instance()->guards->counter()->shares($text);
    }

    /**
     * Fresh read of the kill switch option, the constant and the network
     * switch.
     */
    function translate_sidecar_is_enabled(): bool
    {
        return Plugin::instance()->settings->isEnabled();
    }

    /**
     * Calls left today.
     */
    function translate_sidecar_cap_remaining(): int
    {
        return Plugin::instance()->gate->remaining();
    }

    /**
     * Links to the routed translations of a post. Empty when there are
     * none.
     *
     * @param array{class?:string, show_original?:bool} $args
     */
    function translate_sidecar_language_switcher(int $post_id = 0, array $args = []): string
    {
        return Plugin::instance()->frontend->switcher($post_id, $args);
    }
}
