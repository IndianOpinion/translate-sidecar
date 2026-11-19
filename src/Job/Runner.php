<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

use IndianOpinion\TranslateSidecar\Guard\Guards;
use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Guard\Thresholds;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Segment\PlaceholderException;
use IndianOpinion\TranslateSidecar\Segment\Reassembler;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;
use IndianOpinion\TranslateSidecar\Support\Log;
use IndianOpinion\TranslateSidecar\Translator\TranslationRequest;
use IndianOpinion\TranslateSidecar\Translator\TranslatorException;
use IndianOpinion\TranslateSidecar\Translator\Translators;

/**
 * Runs queued jobs (cases 78 to 86). A job either passes every call and
 * every guard and becomes a candidate, or fails and stores nothing.
 */
final class Runner
{
    public const BACKOFF = [300, 1800, 7200, 43200];
    public const STALLED_AFTER = 3600;
    public const STOPPERS = ['disabled', 'credit_paused', 'capped', 'spend_denied', 'no_adapter'];

    /** @var callable(): float */
    private $timer;

    public function __construct(
        private readonly Store $store,
        private readonly Registry $registry,
        private readonly Settings $settings,
        private readonly Posts $posts,
        private readonly Gate $gate,
        private readonly Translators $translators,
        private readonly GuardsFactory $guards,
        private readonly Clock $clock,
        ?callable $timer = null,
    ) {
        $this->timer = $timer ?? static fn (): float => microtime(true);
    }

    /**
     * One tick: takes the lock, processes up to `max` jobs within `time`
     * seconds, releases the lock.
     *
     * @param array{max?:int, time?:int, lang?:string} $opts
     */
    public function tick(array $opts = []): RunSummary
    {
        $summary = new RunSummary();
        $start = ($this->timer)();
        $max = max(1, (int) ($opts['max'] ?? $this->settings->int('jobs_per_tick')));
        $time = max(1, (int) ($opts['time'] ?? $this->settings->int('time_budget')));
        if (!$this->store->lock('tick', $time + 30)) {
            $summary->stopped = 'locked';

            return $summary;
        }
        try {
            update_option('translate_sidecar_last_tick', $this->clock->now(), false);
            $this->recoverStalled();
            $blocked = $this->gate->blocked();
            if ($blocked !== null) {
                $summary->stopped = $blocked;

                return $summary;
            }
            $jobs = $this->store->dueJobs($this->clock->gmt(), $max, isset($opts['lang']) ? (string) $opts['lang'] : null);
            foreach ($jobs as $job) {
                if (($this->timer)() - $start >= $time) {
                    $summary->stopped = 'time_budget';
                    break;
                }
                $outcome = $this->runJob($job);
                $summary->add($job->id, $outcome);
                if (in_array($outcome, self::STOPPERS, true)) {
                    $summary->stopped = $outcome;
                    break;
                }
            }
        } finally {
            $this->store->unlock('tick');
            $summary->seconds = ($this->timer)() - $start;
            do_action('translate_sidecar_tick_finished', $summary);
        }

        return $summary;
    }

    /**
     * A job left `running` for an hour belongs to a process that died
     * mid-call (a fatal error or a host time limit). Without this it would
     * stay `running` for ever and block new requests for its post and
     * language. It goes back to the queue with an attempt counted, and
     * fails `stalled` once the backoff schedule is used up.
     */
    private function recoverStalled(): void
    {
        try {
            $this->store->recoverStalled(
                $this->clock->gmt($this->clock->now() - self::STALLED_AFTER),
                count(self::BACKOFF),
                $this->clock->gmt()
            );
        } catch (\Throwable $e) {
            Log::error('recovering stalled jobs', $e);
        }
    }

    /**
     * Runs one job by id, for an external queue.
     */
    public function runById(int $jobId): string
    {
        $job = $this->store->find($jobId);
        if ($job === null || $job->state !== Row::QUEUED) {
            return 'skipped';
        }

        return $this->runJob($job);
    }

    public function runJob(Row $job): string
    {
        $started = ($this->timer)();
        if (!$this->store->update($job->id, ['state' => Row::RUNNING, 'updated_gmt' => $this->clock->gmt()], Row::QUEUED)) {
            return 'skipped';
        }
        $job->state = Row::RUNNING;
        try {
            $outcome = $this->process($job);
        } catch (\Throwable $e) {
            $this->fail($job, 'guard_error', ['error' => $e->getMessage()]);
            $outcome = 'failed';
        }
        Log::job([
            'job' => $job->id,
            'post' => $job->postId,
            'lang' => $job->lang,
            'adapter' => $job->adapter,
            'outcome' => $outcome,
            'reason' => $job->reason,
            'ms' => (int) ((($this->timer)() - $started) * 1000),
        ]);

        return $outcome;
    }

    private function process(Row $job): string
    {
        $post = $this->posts->get($job->postId);
        $lang = $this->registry->resolve($job->lang);
        if ($post === null || $lang === null) {
            return $this->fail($job, 'not_eligible', ['detail' => $post === null ? 'post missing' : 'language not registered']);
        }
        $refusal = $this->posts->refusal($post, $lang);
        if ($refusal !== null) {
            return $this->fail($job, $refusal, []);
        }
        $translator = $this->translators->forLanguage($lang);
        if ($translator === null) {
            return $this->requeue($job, 'no_adapter');
        }
        $job->adapter = $translator->id();

        $fields = $this->posts->sourceFields($post);
        $hash = $fields->hash();
        $segmented = $this->posts->segmenter()->segment($fields);
        $max = (int) apply_filters('translate_sidecar_max_segments', $this->settings->int('max_segments'), $post);
        if ($segmented->count() > $max) {
            return $this->fail($job, 'too_long', ['segments' => $segmented->count(), 'max' => $max]);
        }
        $budget = (int) apply_filters('translate_sidecar_segment_budget', $this->settings->int('segment_budget'), $lang->prefix);
        $budget = max(1, min($budget, $translator->maxCharsPerCall()));
        $plan = Plan::build($segmented, $this->store->memoryGet($lang->prefix, array_values($segmented->hashes())), $budget);

        $blocked = $this->gate->blocked();
        if ($blocked !== null) {
            return $this->requeue($job, $blocked);
        }
        if ($this->gate->remaining() < $plan->callCount()) {
            do_action('translate_sidecar_capped', $this->clock->localDay(), $this->settings->dailyCap());

            return $this->requeue($job, 'capped');
        }

        $translations = $plan->fromMemory;
        $guards = $this->guards->make();
        $thresholds = $this->guards->thresholds($lang);
        $report = new GuardReport();
        $provider = '';
        $model = '';
        $sourceLang = $this->posts->sourceLanguage($post);
        $context = [
            'title' => $fields->title,
            'post_type' => (string) $post->post_type,
            'glossary' => (array) apply_filters('translate_sidecar_glossary', [], $lang->prefix),
            'do_not_translate' => array_values((array) $this->settings->get('do_not_translate')),
        ];

        foreach ($plan->calls as $call) {
            // Checks repeat before every call: state may change mid-job.
            $blocked = $this->gate->blocked();
            if ($blocked !== null) {
                return $this->requeue($job, $blocked);
            }
            $texts = array_map(static fn ($s): string => $s->text, $call);
            $request = new TranslationRequest($sourceLang, $lang->hreflang, $texts, $context, $job->id);
            $chars = $request->chars();
            if (!$this->gate->reserveCall($chars)) {
                do_action('translate_sidecar_capped', $this->clock->localDay(), $this->settings->dailyCap());

                return $this->requeue($job, 'capped');
            }
            $spend = $this->gate->spendPre(['units' => $chars, 'job_id' => $job->id, 'lang' => $lang->prefix]);
            if (!$spend['allowed']) {
                $this->gate->spendRelease($spend['reservation']);

                return $this->requeue($job, 'spend_denied');
            }
            try {
                $response = $translator->translate($request);
            } catch (TranslatorException $e) {
                $this->gate->spendRelease($spend['reservation']);

                return $this->providerError($job, $e);
            } catch (\Throwable $e) {
                $this->gate->spendRelease($spend['reservation']);

                return $this->providerError($job, new TranslatorException($e->getMessage(), 'adapter_error', true, false, $e));
            }
            $this->gate->spendRecord($spend['reservation'], $response->usage);
            $provider = $response->provider;
            $model = $response->model;
            try {
                $guards->checkResponse($request, $response, $call, $lang, $thresholds, $report);
            } catch (\Throwable $e) {
                $report->fail('guard_error', '', ['error' => $e->getMessage()]);
            }
            if (!$report->passed()) {
                return $this->fail($job, (string) $report->firstReason(), [], $report);
            }
            foreach (array_keys($call) as $id) {
                $translations[$id] = $response->segments[$id];
            }
        }

        try {
            $assembled = (new Reassembler())->assemble($segmented, $translations);
        } catch (PlaceholderException $e) {
            return $this->fail($job, 'placeholder_mismatch', ['error' => $e->getMessage()]);
        }
        $sourceHtml = Segmenter::hasBlocks($fields->content) || !function_exists('wpautop') ? $fields->content : wpautop($fields->content);
        try {
            $guards->checkAssembled($segmented, $assembled, $sourceHtml, $lang, $thresholds, $report);
        } catch (\Throwable $e) {
            $report->fail('guard_error', '', ['error' => $e->getMessage()]);
        }
        if (!$report->passed()) {
            return $this->fail($job, (string) $report->firstReason(), [], $report);
        }

        // Source changed while the job ran?
        clean_post_cache($job->postId);
        $fresh = $this->posts->get($job->postId);
        if ($fresh === null || $this->posts->sourceFields($fresh)->hash() !== $hash) {
            $this->fail($job, 'source_changed', []);
            if ($lang->auto && $fresh !== null && !str_contains($job->note, 'requeued')) {
                $retry = new Row();
                $retry->postId = $job->postId;
                $retry->lang = $job->lang;
                $retry->state = Row::QUEUED;
                $retry->sourceHash = $this->posts->sourceFields($fresh)->hash();
                $retry->createdGmt = $this->clock->gmt();
                $retry->updatedGmt = $retry->createdGmt;
                $retry->nextAttemptGmt = $retry->createdGmt;
                $retry->createdBy = $job->createdBy;
                $retry->note = 'requeued';
                $id = $this->store->insert($retry);
                do_action('translate_sidecar_job_queued', $id, $job->postId, $job->lang);
            }

            return 'failed';
        }

        $segments = [];
        foreach ($segmented->segments as $id => $seg) {
            $segments[] = ['id' => $id, 'hash' => $seg->hash, 'text' => $assembled->segmentTexts[$id] ?? ''];
        }
        $lock = 'row_' . $job->postId . '_' . $job->lang;
        if (!$this->store->lock($lock, 30)) {
            return $this->requeue($job, 'busy');
        }
        try {
            foreach ($this->store->rowsFor($job->postId, $job->lang, [Row::CANDIDATE]) as $old) {
                $this->store->update($old->id, ['state' => Row::REJECTED, 'reason' => 'replaced', 'updated_gmt' => $this->clock->gmt()], Row::CANDIDATE);
            }
            $ok = $this->store->update($job->id, [
                'state' => Row::CANDIDATE,
                'title' => $assembled->title,
                'excerpt' => $assembled->excerpt,
                'seo_title' => $assembled->seoTitle,
                'meta_description' => $assembled->metaDescription,
                'content' => $assembled->content,
                'alt_text' => Row::encode($assembled->altMap),
                'source_hash' => $hash,
                'segment_hashes' => Row::encode($segments),
                'adapter' => $translator->id(),
                'provider' => substr($provider, 0, 64),
                'model' => substr($model, 0, 128),
                'guard_report' => Row::encode($report->toArray()),
                'reason' => '',
                'updated_gmt' => $this->clock->gmt(),
            ], Row::RUNNING);
        } finally {
            $this->store->unlock($lock);
        }
        if (!$ok) {
            return 'skipped';
        }
        do_action('translate_sidecar_candidate_ready', $job->id, $job->postId, $job->lang);

        return 'candidate';
    }

    private function providerError(Row $job, TranslatorException $e): string
    {
        if ($e->creditExhausted) {
            $this->settings->pauseCreditUntil($this->clock->nextLocalMidnight());

            return $this->requeue($job, 'credit_paused');
        }
        if ($e->retryable) {
            $attempt = $job->attempts + 1;
            if ($attempt > count(self::BACKOFF)) {
                return $this->fail($job, 'provider_error', ['code' => $e->errorCode, 'error' => $e->getMessage(), 'attempts' => $attempt]);
            }
            $job->attempts = $attempt;
            $this->store->update($job->id, [
                'state' => Row::QUEUED,
                'attempts' => $attempt,
                'reason' => $e->errorCode,
                'next_attempt_gmt' => $this->clock->gmt($this->clock->now() + self::BACKOFF[$attempt - 1]),
                'updated_gmt' => $this->clock->gmt(),
            ], Row::RUNNING);

            return 'retry';
        }
        $reason = in_array($e->errorCode, ['bad_shape', 'truncated'], true) ? $e->errorCode : 'provider_error';

        return $this->fail($job, $reason, ['code' => $e->errorCode, 'error' => $e->getMessage()]);
    }

    /**
     * Puts a running job back in the queue without counting an attempt.
     */
    private function requeue(Row $job, string $reason): string
    {
        $job->reason = $reason;
        $this->store->update($job->id, ['state' => Row::QUEUED, 'reason' => $reason, 'updated_gmt' => $this->clock->gmt()], Row::RUNNING);

        return $reason;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function fail(Row $job, string $reason, array $detail, ?GuardReport $report = null): string
    {
        $report ??= new GuardReport();
        if ($report->passed()) {
            $report->fail($reason, '', $detail);
        }
        $job->reason = $reason;
        $this->store->update($job->id, [
            'state' => Row::FAILED,
            'reason' => substr($reason, 0, 64),
            'adapter' => $job->adapter,
            'guard_report' => Row::encode($report->toArray()),
            'updated_gmt' => $this->clock->gmt(),
        ], Row::RUNNING);
        do_action('translate_sidecar_job_failed', $job->id, $reason, $detail + ['failures' => $report->failures()]);

        return 'failed';
    }
}
