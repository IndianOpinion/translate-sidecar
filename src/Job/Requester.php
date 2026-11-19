<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;

/**
 * Creates translation jobs (cases 13 to 17).
 */
final class Requester
{
    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
        private readonly Gate $gate,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Resolves a requested language. Returns the language or a refusal
     * reason.
     */
    public function resolveLanguage(\WP_Post $post, string $lang): Language|string
    {
        $language = $this->registry->resolve($lang);
        if ($language === null) {
            $isSource = strtolower($lang) === strtolower($this->settings->sourceLanguage())
                || Language::tagsMatch($lang, $this->settings->sourceLanguage())
                || ($this->settings->sourcePrefix() !== '' && $lang === $this->settings->sourcePrefix());

            return $isSource ? ($this->posts->nativeLanguage($post) !== null ? 'no_route' : 'same_language') : 'unknown_language';
        }
        $source = $this->registry->sourceEntry();
        if ($source !== null && $language->prefix === $source->prefix && $this->posts->nativeLanguage($post) === null) {
            return 'same_language';
        }

        return $language;
    }

    /**
     * @param list<string> $langs
     * @param array{replace?:bool, dry_run?:bool, source?:string, user_id?:int} $args
     * @return array<string, array<string, mixed>> result per language
     */
    public function request(int $postId, array $langs, array $args = []): array
    {
        $out = [];
        $post = $this->posts->get($postId);
        foreach ($langs as $lang) {
            $lang = strtolower(trim((string) $lang));
            if ($lang === '') {
                continue;
            }
            if ($post === null) {
                $out[$lang] = ['status' => 'refused', 'reason' => 'not_found'];
                continue;
            }
            try {
                $out[$lang] = $this->one($post, $lang, $args);
            } catch (\Throwable $e) {
                $out[$lang] = ['status' => 'refused', 'reason' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * @param array{replace?:bool, dry_run?:bool, source?:string, user_id?:int} $args
     * @return array<string, mixed>
     */
    private function one(\WP_Post $post, string $lang, array $args): array
    {
        $language = $this->resolveLanguage($post, $lang);
        if (is_string($language)) {
            return ['status' => 'refused', 'reason' => $language];
        }
        $refusal = $this->posts->refusal($post, $language);
        if ($refusal !== null) {
            return ['status' => 'refused', 'reason' => $refusal];
        }
        $fields = $this->posts->sourceFields($post);
        $segmented = $this->posts->segmenter()->segment($fields);
        $max = (int) apply_filters('translate_sidecar_max_segments', $this->settings->int('max_segments'), $post);
        if ($segmented->count() > $max) {
            return ['status' => 'refused', 'reason' => 'too_long', 'segments' => $segmented->count(), 'max' => $max];
        }
        $budget = (int) apply_filters('translate_sidecar_segment_budget', $this->settings->int('segment_budget'), $language->prefix);
        $memory = $this->store->memoryGet($language->prefix, array_values($segmented->hashes()));
        $plan = Plan::build($segmented, $memory, max(1, $budget));
        $report = [
            'segments' => $segmented->count(),
            'from_memory' => count($plan->fromMemory),
            'sent_segments' => $plan->sentSegments(),
            'calls' => $plan->callCount(),
            'chars' => $plan->sentChars(),
        ];

        if (!empty($args['dry_run'])) {
            $charsLeft = $this->gate->charsRemaining();

            return ['status' => 'dry_run'] + $report + [
                'cap_remaining' => $this->gate->remaining(),
                'cap_allows' => $this->gate->blocked() === null
                    && $this->gate->remaining() >= $plan->callCount()
                    && ($charsLeft === null || $charsLeft >= $plan->sentChars()),
            ];
        }

        $hash = $fields->hash();
        $active = $this->store->activeJob((int) $post->ID, $language->prefix);
        if ($active !== null) {
            if ($active->sourceHash !== $hash && $active->state === Row::QUEUED) {
                $this->store->update($active->id, ['source_hash' => $hash, 'updated_gmt' => $this->clock->gmt()], Row::QUEUED);
            }

            return ['status' => 'exists', 'job_id' => $active->id] + $report;
        }
        $candidates = $this->store->rowsFor((int) $post->ID, $language->prefix, [Row::CANDIDATE]);
        if ($candidates !== [] && empty($args['replace'])) {
            return ['status' => 'needs_confirmation', 'reason' => 'candidate_exists', 'row_id' => $candidates[0]->id] + $report;
        }

        $row = new Row();
        $row->postId = (int) $post->ID;
        $row->lang = $language->prefix;
        $row->state = Row::QUEUED;
        $row->sourceHash = $hash;
        $row->createdGmt = $this->clock->gmt();
        $row->updatedGmt = $row->createdGmt;
        $row->nextAttemptGmt = $row->createdGmt;
        $row->createdBy = (int) ($args['user_id'] ?? 0);
        $row->note = (string) ($args['source'] ?? '');
        $id = $this->store->insert($row);

        if ($this->settings->bool('use_queue')) {
            $external = apply_filters(
                'gen_queue_enqueue_job',
                null,
                'translate_sidecar_translate',
                ['job_id' => $id, 'post_id' => (int) $post->ID, 'lang' => $language->prefix],
                ['idempotency_key' => self::idempotencyKey((int) $post->ID, $language->prefix, $hash)]
            );
            if ($external !== null && $external !== false) {
                // Parked for the external queue; the plugin's own tick skips it.
                $this->store->update($id, [
                    'next_attempt_gmt' => '9999-12-31 00:00:00',
                    'note' => 'queue:' . (is_scalar($external) ? (string) $external : 'yes'),
                ]);
            }
        }
        do_action('translate_sidecar_job_queued', $id, (int) $post->ID, $language->prefix);

        return ['status' => 'queued', 'job_id' => $id] + $report;
    }

    public static function idempotencyKey(int $postId, string $lang, string $hash): string
    {
        return $postId . ':' . $lang . ':' . $hash;
    }
}
