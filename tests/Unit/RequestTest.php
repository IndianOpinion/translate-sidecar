<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Admin\Actions;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Rest\Controller;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 6 to 15, 20 and 21 (cases 7 to 17, 23, 24, 99).
 */
final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
    }

    /** Test 6 (cases 7, 22). */
    public function testHierarchicalTypeRoutesByFullPath(): void
    {
        add_filter('translate_sidecar_post_types', static fn (array $t): array => array_merge($t, ['guide']));
        \WpStub::addPost(['ID' => 30, 'post_type' => 'guide', 'post_name' => 'parent', 'post_title' => 'Parent guide']);
        $this->post(31, ['post_type' => 'guide', 'post_name' => 'child', 'post_parent' => 30]);
        $this->live(31, 'fr');
        $d = $this->plugin->router->decide('fr', 'parent/child/');
        self::assertSame(200, $d->status);
        self::assertSame(31, $d->postId);
        self::assertSame('https://example.test/fr/parent/child/', $this->plugin->translations->url(31, 'fr'));
    }

    /** Test 7 (case 8). */
    public function testIneligiblePostsNeverReachTheAdapter(): void
    {
        $this->post(1, ['post_status' => 'draft']);
        $this->post(2, ['post_status' => 'future']);
        $this->post(3, ['post_status' => 'private']);
        $this->post(4, ['post_password' => 'secret']);
        foreach ([1, 2, 3, 4] as $id) {
            $r = $this->plugin->requester->request($id, ['fr']);
            self::assertSame('refused', $r['fr']['status']);
            self::assertSame('not_eligible', $r['fr']['reason']);
        }
        add_filter('translate_sidecar_is_eligible', static fn (bool $ok, \WP_Post $p): bool => $p->ID !== 5, 10, 2);
        $this->post(5);
        self::assertSame('not_eligible', $this->plugin->requester->request(5, ['fr'])['fr']['reason']);
        $this->plugin->runner->tick();
        self::assertSame([], $this->spy->calls);
    }

    /** Test 8 (cases 9, 95). */
    public function testMinimumLength(): void
    {
        $this->plugin->settings->save(['min_words' => 150]);
        $this->post(1, ['post_content' => '<p>' . str_repeat('word ', 100) . '</p>']);
        self::assertSame('too_short', $this->plugin->requester->request(1, ['fr'])['fr']['reason']);
        $this->post(2, ['post_content' => '<p>' . str_repeat('word ', 160) . '</p>']);
        self::assertSame('queued', $this->plugin->requester->request(2, ['fr'])['fr']['status']);
        // Spaceless script: 400 characters count as 100 words, 640 as 160.
        $this->post(3, ['post_content' => '<p>' . str_repeat('市議会予算', 80) . '</p>']);
        self::assertSame('too_short', $this->plugin->requester->request(3, ['fr'])['fr']['reason']);
        $this->post(4, ['post_content' => '<p>' . str_repeat('市議会予算', 128) . '</p>']);
        self::assertSame(160, $this->plugin->posts->wordCount(\WpStub::$posts[4]));
        // Only an image: no body text.
        $this->post(6, ['post_content' => '<!-- wp:image --><figure><img src="a.jpg" alt=""/></figure><!-- /wp:image -->']);
        self::assertSame('too_short', $this->plugin->requester->request(6, ['fr'])['fr']['reason']);
    }

    /** Test 9 (case 10). */
    public function testDoNotTranslateFlagRefusesNewJobsAndKeepsLiveRows(): void
    {
        $this->post(1);
        $this->live(1, 'fr');
        update_post_meta(1, Posts::META_SKIP, 1);
        self::assertSame('excluded', $this->plugin->requester->request(1, ['fr'])['fr']['reason']);
        $this->plugin->translations->forget(1);
        self::assertSame(200, $this->plugin->router->decide('fr', 'council-approves-budget-1')->status);
    }

    /** Test 10 (case 11). */
    public function testSameLanguageIsRefused(): void
    {
        $this->post(1);
        $this->plugin->settings->save(['source_lang' => 'fr']);
        self::assertSame('same_language', $this->plugin->requester->request(1, ['fr'])['fr']['reason']);
    }

    /** Test 11 (case 12). */
    public function testNativePostIsAnOriginal(): void
    {
        $this->plugin->settings->save(['source_lang' => 'en']);
        $this->language('de', ['scripts' => ['Latin']]);
        $post = $this->post(1, ['post_name' => 'nativ']);
        update_post_meta(1, Posts::META_SOURCE_LANG, 'fr');
        self::assertSame('same_language', $this->plugin->requester->request(1, ['fr'])['fr']['reason']);
        self::assertSame('queued', $this->plugin->requester->request(1, ['de'])['de']['status']);
        self::assertSame('no_route', $this->plugin->requester->request(1, ['en'])['en']['reason']);
        self::assertSame('https://example.test/nativ/', get_permalink($post), 'The post is not moved.');

        $this->plugin->settings->save(['source_prefix' => 'en']);
        $this->plugin->registry->reset();
        self::assertSame('queued', $this->plugin->requester->request(1, ['en'])['en']['status']);
    }

    /** Test 12 (cases 13, 14). */
    public function testEveryEntryPointCreatesOneJobAndDuplicatesReportIt(): void
    {
        \WpStub::$currentUser = 1;
        $this->post(1);
        $first = translate_sidecar_request(1, ['fr']);
        self::assertSame('queued', $first['fr']['status']);
        $again = translate_sidecar_request(1, ['fr']);
        self::assertSame('exists', $again['fr']['status']);
        self::assertSame($first['fr']['job_id'], $again['fr']['job_id']);

        $rest = (new Controller($this->plugin))->request(['id' => 1, 'langs' => ['fr']]);
        self::assertSame('exists', $rest['fr']['status']);
        $admin = (new Actions($this->plugin))->handle('request', ['_wpnonce' => wp_create_nonce('translate_sidecar_request'), 'post_id' => 1, 'langs' => ['fr']]);
        self::assertTrue($admin['ok']);
        self::assertSame('exists', $admin['data']['fr']['status']);
        self::assertCount(1, $this->store->rowsFor(1, 'fr'));
    }

    /** Test 13 (case 15). */
    public function testReplaceKeepsOldCandidateUntilNewOneExists(): void
    {
        $this->post(1);
        $old = $this->translate(1, 'fr');
        self::assertSame(Row::CANDIDATE, $old?->state);
        $ask = $this->plugin->requester->request(1, ['fr']);
        self::assertSame('needs_confirmation', $ask['fr']['status']);
        $job = $this->plugin->requester->request(1, ['fr'], ['replace' => true]);
        self::assertSame('queued', $job['fr']['status']);
        self::assertSame(Row::CANDIDATE, $this->store->find((int) $old?->id)?->state, 'Old candidate survives while queued.');
        $this->plugin->runner->runById((int) $job['fr']['job_id']);
        self::assertSame(Row::REJECTED, $this->store->find((int) $old?->id)?->state);
        self::assertSame('replaced', $this->store->find((int) $old?->id)?->reason);
        self::assertSame(Row::CANDIDATE, $this->store->find((int) $job['fr']['job_id'])?->state);
    }

    /** Test 14 (case 16). */
    public function testAutoQueuesOnPublishAndOnChangedUpdatesOnly(): void
    {
        $this->plugin->registry->setSwitch('fr', 'auto', true);
        $this->plugin->lifecycle->register();
        $post = $this->post(1, ['post_status' => 'draft']);
        $post->post_status = 'publish';
        do_action('transition_post_status', 'publish', 'draft', $post);
        $jobs = $this->store->rowsFor(1, 'fr', [Row::QUEUED]);
        self::assertCount(1, $jobs);
        $this->plugin->runner->runById($jobs[0]->id);
        $this->plugin->review->approve($jobs[0]->id, 2, ['with_warnings' => true, 'reason' => 'x']);

        $before = clone $post;
        do_action('post_updated', 1, $post, $before);
        self::assertSame([], $this->store->rowsFor(1, 'fr', [Row::QUEUED]), 'Unchanged update queues nothing.');

        $after = clone $post;
        $after->post_title = 'Council approves a different budget';
        \WpStub::$posts[1] = $after;
        do_action('post_updated', 1, $after, $before);
        self::assertCount(1, $this->store->rowsFor(1, 'fr', [Row::QUEUED]), 'Changed update queues one.');
    }

    /** Test 15 (case 17). */
    public function testDryRunWritesNothing(): void
    {
        $this->post(1);
        $r = $this->plugin->requester->request(1, ['fr'], ['dry_run' => true]);
        self::assertSame('dry_run', $r['fr']['status']);
        self::assertSame(3, $r['fr']['segments']);
        self::assertSame(1, $r['fr']['calls']);
        self::assertTrue($r['fr']['cap_allows']);
        self::assertSame([], $this->store->rowsFor(1, 'fr'));
        self::assertSame(0, $this->store->counterGet($this->clock->localDay(), 'calls'));
        self::assertSame([], $this->spy->calls);
    }

    /** Test 20 (cases 23, 99) and ruling 8. */
    public function testSegmentsGroupByBudgetAndCeiling(): void
    {
        $this->plugin->settings->save(['segment_budget' => 200]);
        $paras = '';
        for ($i = 0; $i < 6; $i++) {
            $paras .= '<p>Paragraph number ' . $i . ' talks about the council and the budget in some detail here.</p>';
        }
        $paras .= '<p>' . str_repeat('A very long paragraph about the budget. ', 10) . '</p>';
        $this->post(1, ['post_content' => $paras]);
        $r = $this->plugin->requester->request(1, ['fr'], ['dry_run' => true]);
        $this->plugin->requester->request(1, ['fr']);
        $this->plugin->runner->tick();
        self::assertSame($r['fr']['calls'], count($this->spy->calls));
        $oversize = array_filter($this->spy->calls, static fn ($c): bool => $c->chars() > 200);
        self::assertCount(1, $oversize);
        self::assertCount(1, array_values($oversize)[0]->segments, 'An oversize segment goes alone.');
        foreach ($this->spy->calls as $c) {
            self::assertTrue($c->chars() <= 200 || count($c->segments) === 1);
        }

        $this->plugin->settings->save(['max_segments' => 3]);
        self::assertSame('too_long', $this->plugin->requester->request(1, ['fr'], ['replace' => true])['fr']['reason']);
    }

    /** Test 21 (case 24). */
    public function testRetranslationSendsOnlyChangedSegmentsAndNeverRejectedMemory(): void
    {
        $this->post(1);
        $rejected = $this->translate(1, 'fr');
        $this->plugin->review->reject((int) $rejected?->id, 2, 'bad');
        $this->spy->calls = [];
        $this->translate(1, 'fr');
        self::assertCount(3, $this->spy->sentTexts(), 'Memory from a rejected candidate is never reused.');

        $this->store->prune([Row::REJECTED], '9999-01-01 00:00:00');
        $this->live(1, 'fr');
        $post = \WpStub::$posts[1];
        $post->post_content = str_replace('long debate', 'short debate', $post->post_content);
        $this->spy->calls = [];
        $this->translate(1, 'fr');
        self::assertCount(1, $this->spy->sentTexts());
        self::assertStringContainsString('short debate', $this->spy->sentTexts()[0]);
    }
}
