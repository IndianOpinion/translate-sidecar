<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Sitemap\CoreProvider;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 61 to 67 (cases 71 to 77).
 */
final class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true]);
        $this->language('de', ['scripts' => ['Latin'], 'publish' => true, 'index' => false]);
    }

    /** Test 61 (case 71). */
    public function testCoreProviderListsIndexableLanguagesNewestFirstWithLaterLastmod(): void
    {
        $this->post(1, ['post_modified_gmt' => '2030-05-05 05:05:05']);
        $this->post(2);
        $this->live(1, 'fr');
        $this->clock->advance(100);
        $this->live(2, 'fr');
        $this->live(1, 'de');
        $provider = new CoreProvider($this->plugin->sitemaps);
        self::assertSame(['fr'], array_keys($provider->get_object_subtypes()));
        $list = $provider->get_url_list(1, 'fr');
        self::assertSame('https://example.test/fr/council-approves-budget-2/', $list[0]['loc']);
        self::assertSame('https://example.test/fr/council-approves-budget-1/', $list[1]['loc']);
        self::assertSame('2030-05-05T05:05:05Z', $list[1]['lastmod']);
        self::assertSame($this->clock->gmt(), str_replace(['T', 'Z'], [' ', ''], $list[0]['lastmod']));
        self::assertSame([], $provider->get_url_list(1, 'de'));
        self::assertSame(0, $provider->get_max_num_pages('de'));
    }

    /** Test 62 (case 72). */
    public function testOwnRoutesAndRobotsWhenCoreSitemapsAreOff(): void
    {
        \WpStub::$sitemapsEnabled = false;
        $this->post(1);
        $this->live(1, 'fr');
        $rules = $this->plugin->sitemaps->rules();
        self::assertContains('index.php?ts_sitemap=index', $rules);
        $index = $this->plugin->sitemaps->response('index');
        self::assertSame(200, $index['status']);
        self::assertStringContainsString('<loc>https://example.test/translations-sitemap-fr-1.xml</loc>', $index['body']);
        self::assertStringNotContainsString('-de-', $index['body']);
        $child = $this->plugin->sitemaps->response('fr:1');
        self::assertStringContainsString('<loc>https://example.test/fr/council-approves-budget-1/</loc>', $child['body']);
        self::assertSame("User-agent: *\nSitemap: https://example.test/translations-sitemap.xml\n", $this->plugin->sitemaps->robotsTxt("User-agent: *\n", true));
        add_filter('translate_sidecar_sitemap_routes', static fn (): array => ['index' => 'lang-map', 'child' => 'lang-map']);
        self::assertStringContainsString('lang-map.xml', $this->plugin->sitemaps->indexUrl());
        \WpStub::$sitemapsEnabled = true;
        self::assertSame([], $this->plugin->sitemaps->rules());
    }

    /** Test 63 (case 73). */
    public function testPaginationRespectsLimitAndCeiling(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post($i);
            $this->live($i, 'fr');
        }
        add_filter('translate_sidecar_sitemap_max_urls', static fn (): int => 2);
        self::assertSame(3, $this->plugin->sitemaps->maxPages('fr'));
        self::assertCount(2, $this->plugin->sitemaps->build('fr', 1));
        self::assertCount(1, $this->plugin->sitemaps->build('fr', 3));
        self::assertSame(404, $this->plugin->sitemaps->response('fr:4')['status']);
        \WpStub::$hooks['translate_sidecar_sitemap_max_urls'] = [];
        add_filter('translate_sidecar_sitemap_max_urls', static fn (): int => 900000);
        self::assertSame(50000, $this->plugin->sitemaps->perPage());
    }

    /** Test 64 (case 74). */
    public function testGmtValuesStayUtcUnderALocalTimeZone(): void
    {
        \WpStub::$timezone = 'Asia/Kolkata';
        $this->post(1, ['post_modified_gmt' => '2025-01-01 00:00:00']);
        $row = $this->live(1, 'fr');
        $this->store->update($row->id, ['approved_gmt' => '2026-03-04 22:15:00']);
        $entries = $this->plugin->sitemaps->build('fr', 1);
        self::assertSame('2026-03-04T22:15:00Z', $entries[0]['lastmod']);
    }

    /** Test 65 (case 75). */
    public function testEmptyIndexableLanguageIsAnEmptyUrlsetOthers404(): void
    {
        $empty = $this->plugin->sitemaps->response('fr:1');
        self::assertSame(200, $empty['status']);
        self::assertStringContainsString('<urlset', $empty['body']);
        self::assertStringNotContainsString('<url>', $empty['body']);
        self::assertSame(404, $this->plugin->sitemaps->response('de:1')['status']);
        self::assertSame(404, $this->plugin->sitemaps->response('zz:1')['status']);
        $this->plugin->registry->setSwitch('fr', 'publish', false);
        self::assertSame(404, $this->plugin->sitemaps->response('fr:1')['status']);
    }

    /** Test 66 (case 76). */
    public function testAlternatesOnlyWhenOn(): void
    {
        $this->post(1);
        $this->live(1, 'fr');
        $xml = $this->plugin->sitemaps->response('fr:1')['body'];
        self::assertStringNotContainsString('xhtml:link', $xml);
        $this->plugin->settings->save(['sitemap_alternates' => true]);
        $this->plugin->sitemapCache->markDirty(['fr']);
        $this->clock->advance(5);
        $xml = $this->plugin->sitemaps->response('fr:1')['body'];
        self::assertStringContainsString('<xhtml:link rel="alternate" hreflang="fr"', $xml);
        self::assertStringContainsString('hreflang="x-default"', $xml);
    }

    /**
     * Performance budget (section 9): a page costs a fixed number of row
     * queries however many URLs it holds, with alternates on too; a held
     * translation (hold_major, title changed) is still left out.
     */
    public function testPageQueryCountDoesNotGrowWithUrls(): void
    {
        $this->plugin->settings->save(['sitemap_alternates' => true, 'stale_policy' => 'hold_major']);
        foreach (range(1, 12) as $id) {
            $this->post($id);
            $this->live($id, 'fr');
        }
        \WpStub::$posts[3]->post_title = 'A completely different headline';
        $this->plugin->translations->forget(3);
        $this->rebuild();

        $before = $this->store->queryCount();
        $entries = $this->plugin->sitemaps->build('fr', 1);
        $used = $this->store->queryCount() - $before;

        self::assertCount(11, $entries);
        $locs = implode(' ', array_column($entries, 'loc'));
        self::assertStringContainsString('council-approves-budget-4/', $locs);
        self::assertStringNotContainsString('council-approves-budget-3/', $locs, 'Held by hold_major.');
        self::assertArrayHasKey('alternates', $entries[0]);
        self::assertLessThanOrEqual(4, $used, 'Rows, clusters and one reload of the stale row; not one query per URL.');
    }

    /** Test 67 (case 77). */
    public function testCacheFreshnessLockAndDirtyMarking(): void
    {
        $builds = 0;
        $cache = $this->plugin->sitemapCache;
        $build = static function () use (&$builds): array {
            $builds++;

            return [];
        };
        self::assertSame([], $cache->remember('fr', 1, $build));
        self::assertSame([], $cache->remember('fr', 1, $build));
        self::assertSame(1, $builds, 'An empty list is a fresh cached answer.');

        $cache->markDirty(['fr']);
        $this->clock->advance(5);
        $this->store->lock('sitemap_fr_1', 60);
        self::assertSame([], $cache->remember('fr', 1, $build));
        self::assertSame(1, $builds, 'Locked: the previous body is served.');
        $this->store->unlock('sitemap_fr_1');

        $this->store->lock('sitemap_fr_2', 60);
        $cache->remember('fr', 2, $build);
        self::assertSame(2, $builds, 'Locked with no previous body: builds without the lock.');

        $this->post(1);
        $c = $this->translate(1, 'fr');
        $before = $cache->dirtyAt('fr');
        $this->clock->advance(5);
        $this->plugin->review->approve((int) $c?->id, 2, ['with_warnings' => true, 'reason' => 'x']);
        self::assertGreaterThan($before, $cache->dirtyAt('fr'));
        self::assertSame(0, $cache->dirtyAt('de'));
        self::assertCount(1, $this->store->rowsFor(1, 'fr', [Row::LIVE]));
    }

    public function testBuildFailureAnswers503AndLeavesTheIndex(): void
    {
        \WpStub::$sitemapsEnabled = false;
        $this->post(1);
        $this->live(1, 'fr');
        $this->plugin->sitemapCache->markDirty(['fr']);
        $this->clock->advance(5);
        add_filter('translate_sidecar_sitemap_max_urls', static function (): int {
            throw new \RuntimeException('boom');
        });
        $r = $this->plugin->sitemaps->response('fr:1');
        self::assertSame(503, $r['status']);
        self::assertSame('600', $r['headers']['Retry-After']);
        \WpStub::$hooks['translate_sidecar_sitemap_max_urls'] = [];
        self::assertStringNotContainsString('-fr-', $this->plugin->sitemaps->indexXml());
    }
}
