<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Support;

use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\MemoryStore;
use IndianOpinion\TranslateSidecar\Storage\Row;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected MemoryStore $store;
    protected FakeClock $clock;
    protected Plugin $plugin;
    protected SpyTranslator $spy;

    protected function setUp(): void
    {
        parent::setUp();
        \WpStub::reset();
        $this->store = new MemoryStore();
        $this->clock = new FakeClock();
        $this->store->clock = fn (): int => $this->clock->now();
        $this->spy = new SpyTranslator();
        $spy = $this->spy;
        add_filter('translate_sidecar_translators', static function (array $t) use ($spy): array {
            $t['spy'] = $spy;

            return $t;
        });
        $this->rebuild();
        $this->plugin->settings->save(['adapter' => 'spy', 'min_words' => 5]);
        \WpStub::$caps[1] = ['edit_others_posts', 'manage_options'];
        \WpStub::$caps[2] = ['edit_others_posts'];
        \WpStub::$caps[3] = ['read'];
    }

    protected function rebuild(): void
    {
        $this->plugin = new Plugin($this->store, $this->clock);
        Plugin::setInstance($this->plugin);
    }

    /**
     * Registers a language directly in the stored registry.
     *
     * @param array<string, mixed> $extra
     */
    protected function language(string $prefix, array $extra = []): void
    {
        $stored = get_option(Registry::OPTION, []);
        $stored[] = array_merge(['prefix' => $prefix, 'hreflang' => $prefix, 'name' => strtoupper($prefix)], $extra);
        update_option(Registry::OPTION, $stored);
        $this->plugin->registry->reset();
    }

    protected function post(int $id, array $data = []): \WP_Post
    {
        $body = $data['post_content'] ?? '<!-- wp:paragraph --><p>The council approved the new budget for 2026 on Monday evening after a long debate.</p><!-- /wp:paragraph -->'
            . "\n" . '<!-- wp:paragraph --><p>Members said the plan would fund schools, roads and clinics across the district for years.</p><!-- /wp:paragraph -->';

        return \WpStub::addPost(array_merge([
            'ID' => $id,
            'post_title' => 'Council approves budget',
            'post_name' => 'council-approves-budget-' . $id,
            'post_content' => $body,
        ], $data));
    }

    /**
     * Queues and runs a job, returns the candidate row.
     */
    protected function translate(int $postId, string $lang): ?Row
    {
        $result = $this->plugin->requester->request($postId, [$lang], ['user_id' => 1, 'replace' => true]);
        if (($result[$lang]['status'] ?? '') !== 'queued') {
            return null;
        }
        $this->plugin->runner->runById((int) $result[$lang]['job_id']);

        return $this->store->find((int) $result[$lang]['job_id']);
    }

    /**
     * Translates and approves; returns the live row.
     */
    protected function live(int $postId, string $lang): Row
    {
        $row = $this->translate($postId, $lang);
        self::assertNotNull($row);
        self::assertSame(Row::CANDIDATE, $row->state, 'Expected a candidate, got ' . $row->state . ' ' . $row->reason . ' ' . json_encode($row->guardReport));
        $ok = $this->plugin->review->approve($row->id, 2, ['with_warnings' => true, 'reason' => 'test']);
        self::assertTrue($ok);
        $this->plugin->translations->forget($postId);
        $live = $this->store->find($row->id);
        self::assertNotNull($live);

        return $live;
    }

    /**
     * Simulates a front-end request through the router up to the main query.
     *
     * @return array<string, mixed> resulting query vars
     */
    protected function visit(string $lang, string $path): array
    {
        \WpStub::$actionCounts['wp'] = 0;
        $qv = $this->plugin->router->onRequest(['ts_lang' => $lang, 'ts_path' => $path]);
        $d = $this->plugin->router->decision();
        if ($d !== null && $d->isTranslation()) {
            \WpStub::$currentPostId = $d->postId;
            \WpStub::$queriedId = $d->postId;
            \WpStub::$singular = true;
        }
        do_action('wp');

        return $qv;
    }

    protected function visitOriginal(int $postId): void
    {
        $this->plugin->router->setDecision(null);
        \WpStub::$currentPostId = $postId;
        \WpStub::$queriedId = $postId;
        \WpStub::$singular = true;
        do_action('wp');
    }
}
