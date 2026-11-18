<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 29 to 36, 77, 78 and 80 (cases 32 to 39, 87 to 94).
 */
final class RoutingTest extends TestCase
{
    private const SLUG = 'council-approves-budget-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
        $this->post(1);
    }

    /** Test 29 (case 32). */
    public function testNoLiveTranslationIsA302WithShortCache(): void
    {
        $d = $this->plugin->router->decide('fr', self::SLUG);
        self::assertSame(302, $d->status);
        self::assertSame('https://example.test/' . self::SLUG . '/', $d->location);
        self::assertSame('max-age=300', $d->cacheControl);
        $this->visit('fr', self::SLUG);
        self::assertTrue($this->plugin->router->respond(), "A redirect completes the response.");
        self::assertSame([['https://example.test/' . self::SLUG . '/', 302]], \WpStub::$redirects);
    }

    /** Test 30 (case 33). */
    public function testPublishOffMeans302NoHreflangNoSitemap(): void
    {
        $this->live(1, 'fr');
        $this->plugin->registry->setSwitch('fr', 'publish', false);
        $this->plugin->translations->forget(1);
        self::assertSame(302, $this->plugin->router->decide('fr', self::SLUG)->status);
        self::assertSame([], $this->plugin->translations->cluster(1));
        self::assertSame([], $this->plugin->sitemaps->languages());
        self::assertNull(translate_sidecar_url(1, 'fr'));
    }

    /** Test 31 (case 34). */
    public function testNativePostOnItsOwnRouteIs301WithoutLoop(): void
    {
        update_post_meta(1, Posts::META_SOURCE_LANG, 'fr');
        $d = $this->plugin->router->decide('fr', self::SLUG);
        self::assertSame(301, $d->status);
        self::assertSame('https://example.test/' . self::SLUG . '/', $d->location);
        // The target is the post's own permalink, outside every language prefix.
        self::assertStringNotContainsString('/fr/', (string) $d->location);
    }

    /** Test 32 (case 35). */
    public function testPublishOnIndexOffIsNoindexSelfDescribingAndOutOfClusters(): void
    {
        $this->language('de', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
        $this->live(1, 'fr');
        $this->live(1, 'de');
        $this->plugin->registry->setSwitch('fr', 'index', false);
        $this->plugin->translations->forget(1);
        $this->visit('fr', self::SLUG);
        $robots = $this->plugin->head->robots(['index' => true, 'follow' => true]);
        self::assertTrue($robots['noindex']);
        self::assertTrue($robots['follow']);
        self::assertArrayNotHasKey('index', $robots);
        self::assertSame('noindex, follow', $this->plugin->head->headers([])['X-Robots-Tag']);
        $v = $this->plugin->head->values(1, 'fr');
        self::assertSame('https://example.test/fr/' . self::SLUG . '/', $v['canonical']);
        self::assertSame('https://example.test/fr/' . self::SLUG . '/', $v['og_url']);
        self::assertSame('fr', $v['og_locale']);
        self::assertArrayNotHasKey('fr', $this->plugin->translations->cluster(1));
        self::assertArrayHasKey('de', $this->plugin->translations->cluster(1));
        self::assertSame(404, $this->plugin->sitemaps->response('fr:1')['status']);
    }

    /** Test 33 (cases 36, 59, 88). */
    public function testWithdrawnAndTrashedAnswer410(): void
    {
        $row = $this->live(1, 'fr');
        $this->plugin->review->withdraw($row->id, 2);
        $this->plugin->translations->forget(1);
        self::assertSame(410, $this->plugin->router->decide('fr', self::SLUG)->status);

        $this->post(2);
        $this->live(2, 'fr');
        $p = \WpStub::$posts[2];
        $p->post_status = 'trash';
        $p->post_name = 'council-approves-budget-2__trashed';
        $this->plugin->translations->forget(2);
        self::assertSame(410, $this->plugin->router->decide('fr', 'council-approves-budget-2')->status);
        // Restored from the trash: the live row routes again.
        $p->post_status = 'publish';
        $p->post_name = 'council-approves-budget-2';
        $this->plugin->translations->forget(2);
        self::assertSame(200, $this->plugin->router->decide('fr', 'council-approves-budget-2')->status);
    }

    /** Test 34 (case 37). */
    public function testOldSlugIs301ToNewSlug(): void
    {
        $this->live(1, 'fr');
        \WpStub::$posts[1]->post_name = 'new-slug';
        add_post_meta(1, '_wp_old_slug', self::SLUG);
        $this->plugin->translations->forget(1);
        $d = $this->plugin->router->decide('fr', self::SLUG);
        self::assertSame(301, $d->status);
        self::assertSame('https://example.test/fr/new-slug/', $d->location);
    }

    /** Test 35 (case 38): the language home is deferred, so /xx/ is a 302. */
    public function testLanguageHomeOffIs302(): void
    {
        $d = $this->plugin->router->decide('fr', '');
        self::assertSame(302, $d->status);
        self::assertSame('https://example.test/', $d->location);
        $rules = $this->plugin->router->rules();
        self::assertArrayHasKey('^fr/?$', $rules);
        self::assertArrayHasKey('^fr/(.+?)/?$', $rules);
    }

    /** Test 36 (case 39). */
    public function testStalePolicies(): void
    {
        $longBody = '';
        for ($i = 0; $i < 10; $i++) {
            $longBody .= '<p>Paragraph ' . $i . ' of the council report covers spending in detail.</p>';
        }
        $post = $this->post(1, ['post_content' => $longBody]);
        $this->live(1, 'fr');
        $small = str_replace('Paragraph 3 of', 'Paragraph 3, revised, of', $longBody);
        $titled = 'A new title';
        $cases = [
            'serve' => ['small' => 200, 'title' => 200],
            'hold_major' => ['small' => 200, 'title' => 302],
            'hold' => ['small' => 302, 'title' => 302],
        ];
        foreach ($cases as $policy => $expect) {
            $this->plugin->settings->save(['stale_policy' => $policy]);
            $post->post_content = $small;
            $post->post_title = 'Council approves budget';
            $this->plugin->translations->forget(1);
            self::assertSame($expect['small'], $this->plugin->router->decide('fr', self::SLUG)->status, $policy . ' small change');
            $post->post_content = $longBody;
            $post->post_title = $titled;
            $this->plugin->translations->forget(1);
            self::assertSame($expect['title'], $this->plugin->router->decide('fr', self::SLUG)->status, $policy . ' title change');
        }
        self::assertSame('hold_major', \IndianOpinion\TranslateSidecar\Settings::defaults()['stale_policy']);
    }

    /** Test 77 (cases 87, 89, 90, 91). */
    public function testOriginalLifecycle(): void
    {
        $this->live(1, 'fr');
        foreach (['draft', 'private', 'future', 'pending'] as $status) {
            \WpStub::$posts[1]->post_status = $status;
            $this->plugin->translations->forget(1);
            $d = $this->plugin->router->decide('fr', self::SLUG);
            self::assertSame(302, $d->status, $status);
            self::assertSame('https://example.test/?p=1', $d->location);
        }
        \WpStub::$posts[1]->post_status = 'publish';
        \WpStub::$posts[1]->post_password = 'pw';
        $this->plugin->translations->forget(1);
        self::assertSame(302, $this->plugin->router->decide('fr', self::SLUG)->status);
        \WpStub::$posts[1]->post_password = '';

        add_filter('translate_sidecar_post_types', static fn (): array => ['page']);
        $this->plugin->translations->forget(1);
        self::assertSame(404, $this->plugin->router->decide('fr', self::SLUG)->status, 'Type removed: no route.');
        self::assertNull($this->plugin->translations->routable(1, 'fr'));
        \WpStub::$hooks['translate_sidecar_post_types'] = [];

        $this->plugin->lifecycle->register();
        $this->plugin->sitemapCache->markDirty([]);
        do_action('before_delete_post', 1);
        self::assertSame([], $this->store->rowsFor(1, 'fr'));
        self::assertGreaterThan(0, $this->plugin->sitemapCache->dirtyAt('fr'));
    }

    /** Test 78 (case 92). */
    public function testPlainPermalinks(): void
    {
        $row = $this->translate(1, 'fr');
        update_option('permalink_structure', '');
        self::assertSame([], $this->plugin->router->rules());
        self::assertSame(404, $this->plugin->router->decide('fr', self::SLUG)->status);
        self::assertSame([], $this->plugin->translations->cluster(1));
        self::assertSame([], $this->plugin->sitemaps->languages());
        self::assertSame(404, $this->plugin->sitemaps->response('index')['status']);
        self::assertTrue($this->plugin->review->approve((int) $row?->id, 2, ['with_warnings' => true, 'reason' => 'ok']), 'Review still works.');
    }

    /** Test 80 (case 94). */
    public function testCurrentLanguageBeforeTheMainQuery(): void
    {
        $this->live(1, 'fr');
        \WpStub::$actionCounts['wp'] = 0;
        $this->plugin->router->onRequest(['ts_lang' => 'fr', 'ts_path' => self::SLUG]);
        self::assertSame('', translate_sidecar_current_language());
        self::assertSame('lang="en-US"', $this->plugin->frontend->languageAttributes('lang="en-US"'));
        do_action('wp');
        self::assertSame('fr', translate_sidecar_current_language());
    }
}
