<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 23 to 28, 54 and 83 (cases 26 to 31, 63, 97, 101).
 */
final class FrontendTest extends TestCase
{
    private const SLUG = 'council-approves-budget-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->language('ar', ['scripts' => ['Arabic'], 'no_script_guard' => true, 'publish' => true, 'index' => true, 'dir' => 'rtl', 'locale' => 'ar_AR', 'original_link_label' => 'Original']);
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true, 'original_link_label' => 'Lire l original']);
        $this->post(1);
    }

    /** Test 23 (cases 26, 27, 97). */
    public function testTranslationPageSwapsEverything(): void
    {
        $row = $this->live(1, 'ar');
        $qv = $this->visit('ar', self::SLUG);
        self::assertSame(['ts_lang' => 'ar', 'p' => 1, 'post_type' => 'post'], $qv);
        $f = $this->plugin->frontend;
        self::assertSame(esc_html($row->title), $f->title('Council approves budget', 1));
        $content = (string) $f->content('original body');
        self::assertStringContainsString($row->content, $content);
        self::assertStringContainsString('<!-- wp:paragraph -->', $content, 'Block markup stays.');
        self::assertNotSame('', $f->excerpt('orig', \WpStub::$posts[1]));
        self::assertSame('lang="ar" dir="rtl"', $f->languageAttributes('lang="en-US"'));
        self::assertSame(['title' => $row->title, 'site' => 'Example'], $f->documentTitleParts(['title' => 'x', 'site' => 'Example']));
        self::assertFalse($this->plugin->router->filterRedirectCanonical('https://example.test/' . self::SLUG . '/'));
    }

    /** Test 24 (case 27). */
    public function testOtherPostsKeepTheirTitles(): void
    {
        $this->live(1, 'fr');
        $this->post(2, ['post_title' => 'Related story']);
        $this->visit('fr', self::SLUG);
        self::assertSame('Related story', $this->plugin->frontend->title('Related story', 2));
        \WpStub::$currentPostId = 2;
        self::assertSame('related body', $this->plugin->frontend->content('related body'));
        self::assertSame('related excerpt', $this->plugin->frontend->excerpt('related excerpt', \WpStub::$posts[2]));
    }

    /** Test 25 (case 28). */
    public function testInternalLinksRewriteOnlyToRoutedTranslations(): void
    {
        $this->post(2, ['post_name' => 'routed']);
        $this->post(3, ['post_name' => 'unrouted']);
        $this->live(2, 'fr');
        $this->live(1, 'fr');
        $this->visit('fr', self::SLUG);
        $html = '<p><a href="https://example.test/routed/#top">a</a> <a href="https://example.test/unrouted/">b</a> <a href="https://other.test/routed/">c</a></p>';
        $out = $this->plugin->frontend->rewriteLinks($html, 'fr');
        self::assertStringContainsString('href="https://example.test/fr/routed/#top"', $out);
        self::assertStringContainsString('href="https://example.test/unrouted/"', $out);
        self::assertStringContainsString('href="https://other.test/routed/"', $out);
    }

    /** Test 26 (case 29). */
    public function testCommentsBelongToTheOriginal(): void
    {
        $this->live(1, 'fr');
        $qv = $this->visit('fr', self::SLUG);
        self::assertSame(1, $qv['p'], 'The main query is the original post, so the comment form and list use its id.');
        self::assertSame(1, get_queried_object_id());
        self::assertArrayNotHasKey('comments_template_query_args', \WpStub::$hooks);
        self::assertArrayNotHasKey('comment_form_defaults', \WpStub::$hooks);
    }

    /** Test 27 (case 30). */
    public function testDescriptionFallbackTrimsByGrapheme(): void
    {
        $row = $this->live(1, 'fr');
        $text = str_repeat('नमस्ते दुनिया ', 30);
        $this->store->update($row->id, ['meta_description' => '', 'excerpt' => $text]);
        $this->plugin->translations->forget(1);
        $this->visit('fr', self::SLUG);
        $d = $this->plugin->frontend->description();
        self::assertLessThanOrEqual(155, count(\IndianOpinion\TranslateSidecar\Support\Text::graphemes($d)));
        self::assertTrue(mb_check_encoding($d, 'UTF-8'));
        self::assertStringEndsWith('दुनिया', $d, 'Cut at a word boundary, never through a cluster.');
        self::assertSame($row->title . ' - Example', $this->plugin->frontend->seoTitle());
    }

    /** Test 28 (case 31). */
    public function testOriginalLink(): void
    {
        $this->live(1, 'fr');
        $this->visit('fr', self::SLUG);
        $link = $this->plugin->frontend->originalLink(1, 'fr');
        self::assertStringContainsString('Lire l original', $link);
        self::assertStringContainsString('href="https://example.test/' . self::SLUG . '/"', $link);
        self::assertSame([], $this->spy->calls === [] ? [] : array_filter($this->spy->sentTexts(), static fn ($t) => str_contains($t, 'Lire')), 'No model call writes the label.');
        $this->plugin->settings->save(['source_lang' => 'fr-FR']);
        self::assertSame('', $this->plugin->frontend->originalLink(1, 'fr'));
    }

    /** Test 54 (case 63). */
    public function testSwitcher(): void
    {
        self::assertSame('', translate_sidecar_language_switcher(1));
        $this->live(1, 'fr');
        $html = translate_sidecar_language_switcher(1);
        self::assertStringContainsString('hreflang="fr"', $html);
        self::assertStringContainsString('lang="fr"', $html);
        self::assertStringContainsString('https://example.test/fr/' . self::SLUG . '/', $html);
    }

    /** Test 83 (case 101, section 9). */
    public function testTranslationPageQueryBudgetWithoutObjectCache(): void
    {
        $this->post(2, ['post_name' => 'routed']);
        $this->live(2, 'fr');
        $this->live(1, 'fr');
        $this->rebuild();
        $before = $this->store->queryCount();
        $this->visit('fr', self::SLUG);
        $d = $this->plugin->router->decision();
        self::assertNotNull($d);
        $this->plugin->frontend->content('x');
        $this->plugin->frontend->title('t', 1);
        $this->plugin->frontend->rewriteLinks('<a href="https://example.test/routed/">r</a>', 'fr');
        $this->plugin->translations->cluster(1);
        self::assertLessThanOrEqual(3, $this->store->queryCount() - $before);

        $this->rebuild();
        $before = $this->store->queryCount();
        $this->visitOriginal(1);
        $this->plugin->translations->cluster(1);
        $this->plugin->head->hreflangLinks(1);
        self::assertSame(1, $this->store->queryCount() - $before, 'An original page costs one query.');
    }
}
