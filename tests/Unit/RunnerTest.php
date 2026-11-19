<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Job\Runner;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Tests\Support\Pseudo;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;
use IndianOpinion\TranslateSidecar\Translator\CallerTranslator;
use IndianOpinion\TranslateSidecar\Translator\TranslationRequest;
use IndianOpinion\TranslateSidecar\Translator\TranslationResponse;
use IndianOpinion\TranslateSidecar\Translator\TranslatorException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Acceptance tests 68 to 76 and 81 (cases 78 to 86, 98).
 */
final class RunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
        $this->post(1);
    }

    private function queue(int $postId = 1): int
    {
        $r = $this->plugin->requester->request($postId, ['fr'], ['replace' => true]);

        return (int) $r['fr']['job_id'];
    }

    /**
     * A process that dies mid-call leaves its job running; after an hour a
     * tick puts it back, and it fails once the backoff schedule is used up.
     */
    public function testStalledRunningJobIsRecovered(): void
    {
        $id = $this->queue();
        self::assertTrue($this->store->update($id, ['state' => Row::RUNNING, 'updated_gmt' => $this->clock->gmt()], Row::QUEUED));
        $this->plugin->runner->tick();
        self::assertSame(Row::RUNNING, $this->store->find($id)?->state, 'A fresh running job is left alone.');

        $this->clock->advance(Runner::STALLED_AFTER + 60);
        $this->plugin->runner->tick();
        $row = $this->store->find($id);
        self::assertSame(Row::CANDIDATE, $row?->state);
        self::assertSame(1, $row?->attempts);

        $this->post(2, ['post_content' => '<p>Text about the harbour, the ferries and the lighthouse on the hill.</p>']);
        $stuck = $this->queue(2);
        $this->store->update($stuck, ['state' => Row::RUNNING, 'attempts' => count(Runner::BACKOFF), 'updated_gmt' => $this->clock->gmt()], Row::QUEUED);
        $this->clock->advance(Runner::STALLED_AFTER + 60);
        $this->plugin->runner->tick();
        self::assertSame(Row::FAILED, $this->store->find($stuck)?->state);
        self::assertSame('stalled', $this->store->find($stuck)?->reason);
    }

    /** Test 68 (case 78). */
    public function testOverlappingTicksAndBudgets(): void
    {
        $this->store->lock('tick', 80);
        self::assertSame('locked', $this->plugin->runner->tick()->stopped);
        self::assertSame([], $this->spy->calls);
        $this->store->unlock('tick');

        for ($i = 2; $i <= 8; $i++) {
            $this->post($i, ['post_content' => '<p>Story number ' . $i . ' about the river and the bridge and the town.</p>']);
            $this->queue($i);
        }
        $summary = $this->plugin->runner->tick(['max' => 3]);
        self::assertSame(3, $summary->processed);

        $t = 0.0;
        $runner = new Runner($this->store, $this->plugin->registry, $this->plugin->settings, $this->plugin->posts, $this->plugin->gate, $this->plugin->translators, $this->plugin->guards, $this->clock, static function () use (&$t): float {
            $t += 20.0;

            return $t;
        });
        $summary = $runner->tick(['time' => 50]);
        self::assertSame('time_budget', $summary->stopped);
        self::assertLessThan(4, $summary->processed);
    }

    /** Test 69 (case 79). */
    public function testOrderOfChecksAndRepeatInsideJob(): void
    {
        $order = [];
        add_filter('spend_meter_pre_spend', static function ($d) use (&$order) {
            $order[] = 'meter';

            return ['allowed' => true];
        });
        $this->plugin->settings->setKillSwitch(true);
        $this->queue();
        self::assertSame('disabled', $this->plugin->runner->tick()->stopped);
        self::assertSame([], $order);
        $this->plugin->settings->setKillSwitch(false);
        $this->plugin->settings->save(['daily_cap' => 0]);
        self::assertSame('capped', $this->plugin->runner->tick()->stopped);
        self::assertSame([], $order, 'The meter is never asked when the cap refuses.');
        $this->plugin->settings->save(['daily_cap' => 100, 'segment_budget' => 200]);

        // Kill switch flipped between two calls of one job.
        $this->post(2, ['post_content' => str_repeat('<p>A paragraph that is long enough to need its own call because of the budget limit here.</p>', 3)]);
        $id = $this->queue(2);
        $settings = $this->plugin->settings;
        $this->spy->strategy = static function (TranslationRequest $r, int $n) use ($settings): TranslationResponse {
            $settings->setKillSwitch(true);

            return new TranslationResponse(array_map(static fn ($t) => Pseudo::translate($t, 'fr'), $r->segments), 'fr');
        };
        $this->plugin->runner->runById($id);
        self::assertSame(Row::QUEUED, $this->store->find($id)?->state);
        self::assertSame('disabled', $this->store->find($id)?->reason);
        self::assertSame(['meter'], $order);
    }

    /** Test 70 (case 80). */
    public function testCap(): void
    {
        $this->plugin->settings->save(['daily_cap' => 2]);
        $calls = 0;
        $this->spy->strategy = static function (TranslationRequest $r) use (&$calls): TranslationResponse {
            $calls++;
            throw TranslatorException::fatal('nope', 'http_400');
        };
        $this->queue();
        $this->plugin->runner->tick();
        $day = $this->clock->localDay();
        self::assertSame(1, $this->store->counterGet($day, 'calls'), 'A failed call counts.');
        $this->post(2, ['post_content' => '<p>Different text about the harbour, the boats and the weather today.</p>']);
        $this->queue(2);
        $this->plugin->runner->tick();
        self::assertSame(2, $this->store->counterGet($day, 'calls'));
        $this->post(3, ['post_content' => '<p>Yet another text about the market, the prices and the traders here.</p>']);
        $id = $this->queue(3);
        $s = $this->plugin->runner->tick();
        self::assertSame('capped', $s->stopped);
        self::assertSame(2, $calls, 'No call past the cap.');
        self::assertSame(Row::QUEUED, $this->store->find($id)?->state);
        self::assertNotSame([], \WpStub::fired('translate_sidecar_capped'));
        self::assertSame(0, translate_sidecar_cap_remaining());

        // Local midnight resets the counter.
        \WpStub::$timezone = 'Asia/Kolkata';
        $this->clock->time = $this->clock->nextLocalMidnight() + 1;
        self::assertSame(2, translate_sidecar_cap_remaining());

        $this->plugin->settings->save(['daily_cap' => 0]);
        self::assertSame(0, translate_sidecar_cap_remaining());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConstantsOverrideSettings(): void
    {
        define('TRANSLATE_SIDECAR_DAILY_CAP', 7);
        define('TRANSLATE_SIDECAR_DISABLED', true);
        self::assertSame(7, $this->plugin->settings->dailyCap());
        self::assertFalse(translate_sidecar_is_enabled());
        $this->queue();
        self::assertSame('disabled', $this->plugin->runner->tick()->stopped);
        self::assertSame([], $this->spy->calls);
    }

    /** Test 71 (case 81). */
    public function testKillSwitchStopsCallsButNotReading(): void
    {
        $this->live(1, 'fr');
        $this->post(2);
        $c = $this->translate(2, 'fr');
        $this->plugin->settings->setKillSwitch(true);
        self::assertFalse(translate_sidecar_is_enabled());
        $this->post(3, ['post_content' => '<p>Text about the library and its new opening hours for readers.</p>']);
        $id = $this->queue(3);
        $before = count($this->spy->calls);
        $this->plugin->runner->tick();
        self::assertSame($before, count($this->spy->calls));
        self::assertSame(Row::QUEUED, $this->store->find($id)?->state);
        self::assertTrue($this->plugin->review->approve((int) $c?->id, 2, ['with_warnings' => true, 'reason' => 'x']));
        $this->plugin->translations->forget(1);
        self::assertSame(200, $this->plugin->router->decide('fr', 'council-approves-budget-1')->status);
        self::assertNotSame([], $this->plugin->translations->cluster(1));
        self::assertSame(200, $this->plugin->sitemaps->response('fr:1')['status']);
        $this->plugin->settings->setKillSwitch(false);
        self::assertTrue(translate_sidecar_is_enabled());
    }

    /** Test 72 (case 82). */
    public function testProviderErrors(): void
    {
        $this->spy->strategy = static function (): TranslationResponse {
            throw TranslatorException::fromHttpStatus(503);
        };
        $id = $this->queue();
        $expected = [300, 1800, 7200, 43200];
        foreach ($expected as $i => $wait) {
            self::assertSame('retry', $this->plugin->runner->runById($id));
            $row = $this->store->find($id);
            self::assertSame($i + 1, $row?->attempts);
            self::assertSame($this->clock->gmt($this->clock->now() + $wait), $row?->nextAttemptGmt);
        }
        self::assertSame('failed', $this->plugin->runner->runById($id));
        self::assertSame('provider_error', $this->store->find($id)?->reason);

        $this->spy->strategy = static function (): TranslationResponse {
            throw TranslatorException::fromHttpStatus(401);
        };
        $this->post(2, ['post_content' => '<p>Text about a quite different subject, the school sports day.</p>']);
        $id = $this->queue(2);
        self::assertSame('failed', $this->plugin->runner->runById($id));

        $this->spy->strategy = static function (): TranslationResponse {
            throw TranslatorException::fromHttpStatus(402);
        };
        $this->post(3, ['post_content' => '<p>Text about the new hospital wing and its many beds now open.</p>']);
        $id = $this->queue(3);
        self::assertSame('credit_paused', $this->plugin->runner->runById($id));
        self::assertSame(Row::QUEUED, $this->store->find($id)?->state);
        self::assertSame($this->clock->nextLocalMidnight(), $this->plugin->settings->creditPausedUntil());
        self::assertSame('credit_paused', $this->plugin->runner->tick()->stopped);
    }

    /** Test 73 (case 83). */
    public function testFailureInSecondCallStoresNothing(): void
    {
        $this->plugin->settings->save(['segment_budget' => 200]);
        $this->post(2, ['post_content' => str_repeat('<p>A paragraph that is long enough to need its own call because of the budget limit here.</p>', 3)]);
        $this->spy->strategy = static function (TranslationRequest $r, int $n): TranslationResponse {
            if ($n === 2) {
                return new TranslationResponse([], 'fr');
            }

            return new TranslationResponse(array_map(static fn ($t) => Pseudo::translate($t, 'fr'), $r->segments), 'fr');
        };
        $id = $this->queue(2);
        $this->plugin->runner->runById($id);
        $row = $this->store->find($id);
        self::assertSame(Row::FAILED, $row?->state);
        self::assertSame('missing_segments', $row?->reason);
        self::assertSame('', $row?->content);
        self::assertSame([], $row?->segments);
        self::assertSame(0, $this->store->memorySize());
        self::assertGreaterThanOrEqual(2, count($this->spy->calls));
    }

    /** Test 74 (case 84). */
    public function testSourceChangeDuringJobDiscardsIt(): void
    {
        $this->plugin->registry->setSwitch('fr', 'auto', true);
        $this->spy->strategy = static function (TranslationRequest $r): TranslationResponse {
            \WpStub::$posts[1]->post_title = 'Edited while running';

            return new TranslationResponse(array_map(static fn ($t) => Pseudo::translate($t, 'fr'), $r->segments), 'fr');
        };
        $id = $this->queue();
        self::assertSame('failed', $this->plugin->runner->runById($id));
        self::assertSame('source_changed', $this->store->find($id)?->reason);
        $requeued = $this->store->rowsFor(1, 'fr', [Row::QUEUED]);
        self::assertCount(1, $requeued);
        self::assertSame('requeued', $requeued[0]->note);
        $this->spy->strategy = static function (TranslationRequest $r): TranslationResponse {
            \WpStub::$posts[1]->post_title .= '!';

            return new TranslationResponse(array_map(static fn ($t) => Pseudo::translate($t, 'fr'), $r->segments), 'fr');
        };
        $this->plugin->runner->runById($requeued[0]->id);
        self::assertSame([], $this->store->rowsFor(1, 'fr', [Row::QUEUED]), 'Requeued once only.');
    }

    /** Test 75 (case 85). */
    public function testGenQueueBridge(): void
    {
        $this->plugin->settings->save(['use_queue' => true]);
        $seen = [];
        add_filter('gen_queue_enqueue_job', static function ($none, $type, $payload, $opts) use (&$seen) {
            $seen = [$type, $payload, $opts];

            return 77;
        }, 10, 4);
        $id = $this->queue();
        self::assertSame('translate_sidecar_translate', $seen[0]);
        self::assertSame(1, $seen[1]['post_id']);
        $hash = $this->store->find($id)?->sourceHash;
        self::assertSame('1:fr:' . $hash, $seen[2]['idempotency_key']);
        self::assertSame(0, $this->plugin->runner->tick()->processed, 'Parked for the external queue.');
        self::assertSame('candidate', translate_sidecar_run_job($id));

        \WpStub::$hooks['gen_queue_enqueue_job'] = [];
        $this->post(2, ['post_content' => '<p>Text about the bus routes that change next month in the city.</p>']);
        $id2 = $this->queue(2);
        self::assertSame(1, $this->plugin->runner->tick()->processed, 'Absent: the own tick runs it.');
        self::assertSame(Row::CANDIDATE, $this->store->find($id2)?->state);
    }

    /** Test 76 (case 86). */
    public function testSpendMeter(): void
    {
        $log = [];
        add_action('spend_meter_record', static function ($res) use (&$log): void {
            $log[] = 'record:' . $res;
        });
        add_action('spend_meter_release', static function ($res) use (&$log): void {
            $log[] = 'release:' . $res;
        });
        $answer = ['allowed' => false, 'reason' => 'over_budget'];
        add_filter('spend_meter_pre_spend', static function ($none, array $ctx) use (&$answer) {
            self::assertSame('translate_sidecar.translate', $ctx['purpose']);
            self::assertSame('translate-sidecar', $ctx['caller']);

            return $answer;
        }, 10, 2);
        $id = $this->queue();
        self::assertSame('spend_denied', $this->plugin->runner->tick()->stopped);
        self::assertSame([], $this->spy->calls);

        $answer = ['allowed' => true, 'reason' => 'mystery_code', 'reservation' => 'r1'];
        self::assertSame('spend_denied', $this->plugin->runner->tick()->stopped, 'Unknown reason is a deny.');
        self::assertSame(['release:r1'], $log);

        $answer = ['allowed' => 'yes'];
        self::assertSame('spend_denied', $this->plugin->runner->tick()->stopped, 'Not exactly true is a deny.');

        $answer = ['allowed' => true, 'reservation' => 'r2'];
        $this->plugin->runner->tick();
        self::assertSame(['release:r1', 'record:r2'], $log);
        self::assertSame(Row::CANDIDATE, $this->store->find($id)?->state);

        $answer = ['allowed' => true, 'reservation' => 'r3'];
        $this->spy->strategy = static function (): TranslationResponse {
            throw TranslatorException::fatal('bad', 'http_400');
        };
        $this->post(2, ['post_content' => '<p>Text about the night market and its food stalls by the sea.</p>']);
        $this->queue(2);
        $this->plugin->runner->tick();
        self::assertSame(['release:r1', 'record:r2', 'release:r3'], $log, 'Exactly one record or release per reservation.');
    }

    /** Test 81 (case 98). */
    public function testBadShapeFails(): void
    {
        $replies = ["```json\n{\"segments\": []}\n```", 'Sure! Here you go.', ['segments' => [['id' => 's1']]]];
        foreach ($replies as $i => $reply) {
            $caller = static fn (): mixed => $reply;
            add_filter('translate_sidecar_translators', static function (array $t) use ($caller): array {
                $t['spy'] = new CallerTranslator($caller);

                return $t;
            }, 20);
            $this->rebuild();
            $this->post(10 + $i, ['post_content' => '<p>Text number ' . $i . ' about the town hall and its clock tower.</p>']);
            $id = $this->queue(10 + $i);
            $this->plugin->runner->runById($id);
            self::assertSame('bad_shape', $this->store->find($id)?->reason, 'reply ' . $i);
            \WpStub::$hooks['translate_sidecar_translators'][20] = [];
        }
        self::assertSame('caller', (new CallerTranslator(static fn () => []))->id());
    }

    public function testCallerTranslatorEndToEnd(): void
    {
        add_filter('translate_sidecar_llm_caller', static fn () => static function (string $system, string $user, array $schema, array $meta): array {
            self::assertFalse($schema['additionalProperties']);
            self::assertSame('translate_sidecar.translate', $meta['purpose']);
            $payload = json_decode($user, true);
            $segments = [];
            foreach ($payload['segments'] as $s) {
                $segments[] = ['id' => $s['id'], 'text' => Pseudo::translate($s['text'], 'fr')];
            }

            return ['language' => 'fr', 'segments' => $segments, '_meta' => ['provider' => 'p', 'model' => 'm']];
        });
        $this->plugin->settings->save(['adapter' => 'caller']);
        $this->rebuild();
        $row = $this->translate(1, 'fr');
        self::assertSame(Row::CANDIDATE, $row?->state);
        self::assertSame('caller', $row?->adapter);
        self::assertSame('m', $row?->model);
        self::assertInstanceOf(Plugin::class, Plugin::instance());
    }
}
