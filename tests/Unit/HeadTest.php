<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Head\Head;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 55 to 60 (cases 64 to 70) and ruling 5.
 */
final class HeadTest extends TestCase
{
    private const SLUG = 'council-approves-budget-1';
    private const ORIGINAL = 'https://example.test/council-approves-budget-1/';
    private const FR = 'https://example.test/fr/council-approves-budget-1/';
    private const DE = 'https://example.test/de/council-approves-budget-1/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin->settings->save(['source_lang' => 'en']);
        $this->language('fr', ['scripts' => ['Latin'], 'publish' => true, 'index' => true, 'locale' => 'fr_FR']);
        $this->language('de', ['scripts' => ['Latin'], 'publish' => true, 'index' => true, 'locale' => 'de_DE']);
        $this->language('es', ['scripts' => ['Latin'], 'publish' => true, 'index' => false, 'locale' => 'es_ES']);
        $this->post(1);
        $this->live(1, 'fr');
        $this->live(1, 'de');
        $this->live(1, 'es');
        $this->rebuild();
    }

    /** Test 55 (case 64). */
    public function testCanonicalIsSelfOnTranslationAndUntouchedOnOriginal(): void
    {
        $this->visit('fr', self::SLUG);
        self::assertSame(self::FR, $this->plugin->head->values(1, 'fr')['canonical']);
        $html = $this->plugin->head->headHtml('core');
        self::assertStringContainsString('<link rel="canonical" href="' . self::FR . '" />', $html);

        $this->rebuild();
        $this->visitOriginal(1);
        add_action('wp_head', 'rel_canonical');
        $this->plugin->head->setup();
        self::assertStringNotContainsString('rel="canonical"', $this->plugin->head->headHtml('core'));
        self::assertTrue(has_filter('wp_head'));
    }

    /** Test 56 (cases 65, 66). */
    public function testClusterIsIdenticalEverywhereAndExcludesUnindexed(): void
    {
        $expected = ['en' => self::ORIGINAL, 'fr' => self::FR, 'de' => self::DE, 'x-default' => self::ORIGINAL];
        $this->visitOriginal(1);
        $onOriginal = $this->plugin->head->headHtml('filters');
        $this->visit('fr', self::SLUG);
        $onFr = $this->plugin->head->headHtml('filters');
        $this->visit('de', self::SLUG);
        $onDe = $this->plugin->head->headHtml('filters');
        self::assertSame($expected, $this->plugin->translations->cluster(1));
        self::assertSame($onOriginal, $onFr);
        self::assertSame($onOriginal, $onDe);
        self::assertStringNotContainsString('hreflang="es"', $onOriginal);

        $this->post(2);
        self::assertSame([], $this->plugin->translations->cluster(2), 'Only the original: nothing.');
        $this->visitOriginal(2);
        self::assertSame('', $this->plugin->head->headHtml('core'));

        // Held by the stale policy: left out.
        $this->plugin->settings->save(['stale_policy' => 'hold']);
        \WpStub::$posts[1]->post_title = 'Changed title';
        $this->plugin->translations->forget(1);
        self::assertSame([], $this->plugin->translations->cluster(1));
    }

    /** Test 57 (case 67). */
    public function testClusterUrlsAreAbsoluteAndFollowTrailingSlash(): void
    {
        foreach ($this->plugin->translations->cluster(1) as $url) {
            self::assertStringStartsWith('https://example.test/', $url);
            self::assertStringEndsWith('/', $url);
        }
        \WpStub::$trailingSlash = false;
        $this->plugin->translations->forget(1);
        self::assertSame('https://example.test/fr/council-approves-budget-1', $this->plugin->translations->cluster(1)['fr']);
    }

    /** Test 58 (case 68). */
    public function testOpenGraphLocales(): void
    {
        $this->visit('fr', self::SLUG);
        $v = $this->plugin->head->values(1, 'fr');
        self::assertSame('fr_FR', $v['og_locale']);
        self::assertSame(['en', 'de_DE'], $v['og_locale_alternate']);
        $html = $this->plugin->head->headHtml('core');
        self::assertStringContainsString('<meta property="og:locale" content="fr_FR" />', $html);
        self::assertStringContainsString('<meta property="og:locale:alternate" content="de_DE" />', $html);
        self::assertStringContainsString('<meta property="og:url" content="' . self::FR . '" />', $html);
        self::assertStringContainsString('twitter:title', $html);
    }

    /** Test 59 (case 69) and ruling 5. */
    public function testHeadModes(): void
    {
        $this->visit('fr', self::SLUG);
        $core = $this->plugin->head->headHtml('core');
        self::assertStringContainsString('rel="canonical"', $core);
        self::assertStringContainsString('hreflang="de"', $core);
        $filters = $this->plugin->head->headHtml('filters');
        self::assertStringNotContainsString('canonical', $filters);
        self::assertStringContainsString('hreflang="de"', $filters);

        $foreign = '<title>Original title</title>' . "\n"
            . '<link rel="canonical" href="' . self::ORIGINAL . '" />' . "\n"
            . '<meta property="og:url" content="' . self::ORIGINAL . '" />' . "\n"
            . '<meta property="og:locale" content="en_US" />' . "\n"
            . '<meta name="description" content="Original description" />' . "\n"
            . '<link rel="alternate" hreflang="xx" href="https://example.test/xx/" />' . "\n"
            . '<link rel="alternate" type="application/rss+xml" href="https://example.test/feed/" />' . "\n"
            . '<link rel="alternate" type="application/json+oembed" href="https://example.test/oembed" />';
        $out = $this->plugin->head->rewrite($foreign);
        self::assertStringContainsString('<link rel="canonical" href="' . self::FR . '" />', $out);
        self::assertStringContainsString('<meta property="og:url" content="' . self::FR . '" />', $out);
        self::assertStringContainsString('<meta property="og:locale" content="fr_FR" />', $out);
        self::assertStringNotContainsString('hreflang="xx"', $out);
        self::assertStringContainsString('application/rss+xml', $out);
        self::assertStringContainsString('application/json+oembed', $out);
        self::assertStringContainsString('hreflang="fr"', $out);
        self::assertStringNotContainsString('Original title', $out);

        // Detection by inspecting wp_head callbacks, no loopback request.
        $GLOBALS['wp_filter'] = [];
        \WpStub::$hooks = [];
        \WpStub::$actionCounts['init'] = 1;
        add_action('wp_head', 'rel_canonical');
        self::assertFalse(Head::detectSeoPlugin());
        add_action('wp_head', ['Some_Vendor_Seo_Head', 'print_canonical']);
        self::assertTrue(Head::detectSeoPlugin());
        \WpStub::$hooks = [];
        $GLOBALS['wp_filter'] = [];
        add_action('wp_head', 'wp_generator');
        self::assertTrue(Head::detectSeoPlugin(), 'Core canonical removed by someone else.');
    }

    /** Test 60 (case 70). */
    public function testSchemaGraph(): void
    {
        update_post_meta(1, '_thumbnail_url', 'https://example.test/img.jpg');
        $this->visit('fr', self::SLUG);
        $graph = $this->plugin->structuredData->coreGraph(1, 'fr');
        $node = $graph['@graph'][0];
        self::assertSame('fr', $node['inLanguage']);
        self::assertSame(['@id' => self::ORIGINAL . '#article'], $node['translationOfWork']);
        self::assertSame(self::FR, $node['url']);
        self::assertSame(['@id' => self::FR], $node['mainEntityOfPage']);
        self::assertSame(['https://example.test/img.jpg'], $node['image']);
        self::assertSame('2026-01-01T00:00:00+00:00', $node['datePublished']);
        self::assertSame('Author 1', $node['author']['name']);
        self::assertNotSame('Council approves budget', $node['headline']);

        $this->plugin->structuredData->register();
        $filtered = apply_filters('translate_sidecar_schema_graph', ['@type' => 'NewsArticle', '@id' => self::ORIGINAL . '#a', 'image' => 'i.jpg'], 1, 'fr');
        self::assertSame(['@id' => self::ORIGINAL . '#a'], $filtered['translationOfWork']);
        self::assertSame('i.jpg', $filtered['image']);

        $this->visitOriginal(1);
        $orig = $this->plugin->structuredData->coreGraph(1, null)['@graph'][0];
        self::assertSame([['@id' => self::FR . '#article'], ['@id' => self::DE . '#article']], $orig['workTranslation']);
    }
}
