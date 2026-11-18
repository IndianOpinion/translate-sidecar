<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Front\Preview;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 48 to 53 (cases 53 to 62, 102) and ruling 2.
 */
final class ReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
        $this->post(1);
    }

    /** Test 48 (cases 53, 61). */
    public function testHumanEditWithWarningsNeedsTickBoxAndReason(): void
    {
        $id = $this->plugin->review->saveManual(1, 'fr', ['title' => 'Le conseil approuve le budget'], 2);
        self::assertIsInt($id);
        $row = $this->store->find($id);
        self::assertSame('manual', $row?->adapter);
        self::assertSame(Row::CANDIDATE, $row?->state);
        self::assertNotSame([], $row?->guardReport['warnings'], 'Untranslated body segments are reported as warnings.');
        self::assertSame([], $row?->guardReport['failures']);

        $r = $this->plugin->review->approve($id, 2);
        self::assertSame('needs_confirmation', $r instanceof \WP_Error ? $r->get_error_code() : '');
        $r = $this->plugin->review->approve($id, 2, ['with_warnings' => true]);
        self::assertSame('reason_required', $r instanceof \WP_Error ? $r->get_error_code() : '');
        self::assertTrue($this->plugin->review->approve($id, 2, ['with_warnings' => true, 'reason' => 'Names only']));
        $row = $this->store->find($id);
        self::assertSame('approved_with_warnings', $row?->reason);
        self::assertSame('Names only', $row?->note);

        $this->post(2, ['post_title' => 'Rivers rise after rain', 'post_content' => '<p>The river rose two metres overnight after heavy rain in the hills.</p><p>Officials opened shelters near the old bridge for families.</p>']);
        $edit = $this->translate(2, 'fr');
        self::assertSame(Row::CANDIDATE, $edit?->state);
        $bad = $this->plugin->review->saveManual(2, 'fr', ['s1' => 'Le fleuve {9/}'], 2, (int) $edit?->id);
        self::assertSame('placeholder_mismatch', $bad instanceof \WP_Error ? $bad->get_error_code() : '', 'An invented token cannot be restored.');
        $again = $this->plugin->review->saveManual(2, 'fr', ['s1' => 'The river rose two metres overnight after heavy rain in the hills.'], 2, (int) $edit?->id);
        self::assertSame($edit?->id, $again, 'A human edit updates the candidate in place.');
        $reasons = array_column($this->store->find((int) $again)?->guardReport['warnings'] ?? [], 'reason');
        self::assertContains('untranslated', $reasons, 'Guards re-run as warnings after the edit.');
        self::assertSame(Row::CANDIDATE, $this->store->find((int) $again)?->state);
    }

    /** Test 49 (cases 54, 102). */
    public function testStateMachineAndConcurrentApproval(): void
    {
        $c = $this->translate(1, 'fr');
        $this->plugin->translations->forget(1);
        self::assertSame(302, $this->plugin->router->decide('fr', 'council-approves-budget-1')->status, 'A candidate never routes.');
        self::assertTrue($this->plugin->review->approve((int) $c?->id, 2, ['with_warnings' => true, 'reason' => 'x']));
        $second = $this->plugin->review->approve((int) $c?->id, 1, ['with_warnings' => true, 'reason' => 'x']);
        self::assertSame('already_approved', $second instanceof \WP_Error ? $second->get_error_code() : '');
        self::assertCount(1, $this->store->rowsFor(1, 'fr', [Row::LIVE]));

        $this->translate(1, 'fr');
        $this->translate(1, 'fr');
        self::assertCount(1, $this->store->rowsFor(1, 'fr', [Row::CANDIDATE]));
        $this->expectException(\RuntimeException::class);
        $dupe = new Row();
        $dupe->postId = 1;
        $dupe->lang = 'fr';
        $dupe->state = Row::LIVE;
        $this->store->insert($dupe);
    }

    /** Test 50 (case 55). */
    public function testReviewScreenListsCandidatesOldestFirstWithReportAndDiff(): void
    {
        $this->post(2);
        $this->live(1, 'fr');
        $a = $this->translate(2, 'fr');
        $this->clock->advance(10);
        \WpStub::$posts[1]->post_content .= '<p>One more paragraph about the budget was added later.</p>';
        $b = $this->translate(1, 'fr');
        $list = $this->store->candidates('fr', 0, 20);
        self::assertSame([$a?->id, $b?->id], array_map(static fn (Row $r): int => $r->id, $list));
        \WpStub::$currentUser = 2;
        $_GET = ['row' => (string) $b?->id];
        ob_start();
        (new \IndianOpinion\TranslateSidecar\Admin\Admin($this->plugin))->renderReview();
        $html = (string) ob_get_clean();
        $_GET = [];
        self::assertStringContainsString('Guard report', $html);
        self::assertStringContainsString('One more paragraph', $html);
        self::assertStringContainsString(' *</td>', $html, 'Changed segments are marked against the live row.');
    }

    /** Test 51 (case 56). */
    public function testPreview(): void
    {
        $c = $this->translate(1, 'fr');
        \WpStub::$currentUser = 2;
        $_GET = Preview::args(2, (int) $c?->id, time());
        $this->visit('fr', 'council-approves-budget-1');
        self::assertSame(200, $this->plugin->router->decision()?->status);
        self::assertTrue($this->plugin->router->isPreview());
        self::assertSame('noindex, nofollow', $this->plugin->head->headers([])['X-Robots-Tag']);
        self::assertSame('private, no-store', $this->plugin->head->headers([])['Cache-Control']);

        $_GET[Preview::PARAM_TOKEN] = 'forged';
        $this->visit('fr', 'council-approves-budget-1');
        self::assertSame(302, $this->plugin->router->decision()?->status, 'Bad token: normal response.');

        $_GET = Preview::args(2, (int) $c?->id, time() - 7200);
        $this->visit('fr', 'council-approves-budget-1');
        self::assertSame(302, $this->plugin->router->decision()?->status, 'Expired token: normal response.');

        \WpStub::$currentUser = 3;
        $_GET = Preview::args(3, (int) $c?->id, time());
        $this->visit('fr', 'council-approves-budget-1');
        self::assertSame(302, $this->plugin->router->decision()?->status, 'No capability: normal response.');
        $_GET = [];
    }

    /** Test 52 (cases 57 to 60) and ruling 2. */
    public function testApproveRejectWithdrawRollback(): void
    {
        $purges = 0;
        add_action('translate_sidecar_purge_urls', static function (array $urls) use (&$purges): void {
            $purges++;
            self::assertContains('https://example.test/council-approves-budget-1/', $urls);
            self::assertContains('https://example.test/fr/council-approves-budget-1/', $urls);
        });
        $v1 = $this->live(1, 'fr');
        self::assertSame(2, $v1->approvedBy);
        self::assertNotNull($v1->approvedGmt);
        self::assertGreaterThan(0, $this->store->memorySize());
        self::assertSame(1, $purges);

        $c = $this->translate(1, 'fr');
        $memory = $this->store->memorySize();
        $this->plugin->review->reject((int) $c?->id, 2, 'tone');
        self::assertSame(Row::REJECTED, $this->store->find((int) $c?->id)?->state);
        self::assertSame('tone', $this->store->find((int) $c?->id)?->note);
        self::assertSame($memory, $this->store->memorySize(), 'Reject never feeds memory.');
        self::assertSame(Row::LIVE, $this->store->find($v1->id)?->state);

        $ids = [$v1->id];
        for ($i = 0; $i < 4; $i++) {
            \WpStub::$posts[1]->post_title = 'Council approves budget v' . ($i + 2);
            $ids[] = $this->live(1, 'fr')->id;
        }
        $priors = $this->store->rowsFor(1, 'fr', [Row::PRIOR]);
        self::assertCount(3, $priors, 'The last three versions are kept.');
        self::assertSame(Row::REJECTED, $this->store->find($v1->id)?->state);

        self::assertTrue($this->plugin->review->rollback(1, 'fr', 2));
        self::assertSame(Row::LIVE, $this->store->find($ids[3])?->state);
        self::assertSame(Row::REJECTED, $this->store->find($ids[4])?->state);

        self::assertTrue($this->plugin->review->withdraw($ids[3], 2));
        self::assertSame(Row::WITHDRAWN, $this->store->find($ids[3])?->state);
        self::assertGreaterThanOrEqual(7, $purges);
        self::assertSame('no_prior', ($r = $this->plugin->review->rollback(2, 'fr', 2)) instanceof \WP_Error ? $r->get_error_code() : '');
    }

    /** Test 53 (case 62). */
    public function testSelfApprovalCanBeTurnedOff(): void
    {
        $c = $this->translate(1, 'fr');
        self::assertSame(1, $c?->createdBy);
        add_filter('translate_sidecar_allow_self_approval', static fn (): bool => false);
        $r = $this->plugin->review->approve((int) $c?->id, 1, ['with_warnings' => true, 'reason' => 'x']);
        self::assertSame('self_approval', $r instanceof \WP_Error ? $r->get_error_code() : '');
        self::assertTrue($this->plugin->review->approve((int) $c?->id, 2, ['with_warnings' => true, 'reason' => 'x']));
        self::assertSame(2, $this->store->find((int) $c?->id)?->approvedBy);
    }
}
