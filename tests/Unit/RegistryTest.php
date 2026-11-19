<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Posts;
use IndianOpinion\TranslateSidecar\Storage\Row;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;

/**
 * Acceptance tests 1 to 5 (cases 1 to 6, 12).
 */
final class RegistryTest extends TestCase
{
    /** Test 1 (case 2). */
    public function testValidation(): void
    {
        $r = $this->plugin->registry;
        self::assertSame([], $r->save(['prefix' => 'fr', 'hreflang' => 'fr', 'scripts' => ['Latin']]));
        self::assertSame([], $r->save(['prefix' => 'pt-br', 'hreflang' => 'pt-BR', 'locale' => 'pt_BR']));
        self::assertSame('pt-BR', $r->get('pt-br')?->hreflang);

        $cases = [
            'malformed' => [['prefix' => 'F_R', 'hreflang' => 'fr'], 'malformed'],
            'too long' => [['prefix' => 'abcd', 'hreflang' => 'fr'], 'malformed'],
            'duplicate prefix' => [['prefix' => 'fr', 'hreflang' => 'fr-CA'], 'prefix "fr" is already registered'],
            'duplicate hreflang' => [['prefix' => 'fra', 'hreflang' => 'fr'], 'hreflang tag "fr" is already registered'],
            'x-default' => [['prefix' => 'xd', 'hreflang' => 'x-default'], 'x-default'],
            'bad tag' => [['prefix' => 'de', 'hreflang' => 'de_DE_x'], 'not a well-formed'],
            'source language' => [['prefix' => 'en', 'hreflang' => 'en-US'], 'source language'],
            'reserved base' => [['prefix' => 'tag', 'hreflang' => 'tg'], 'reserved base "tag"'],
            'feed' => [['prefix' => 'feed', 'hreflang' => 'fe'], 'malformed'],
        ];
        foreach ($cases as $name => [$entry, $needle]) {
            $errors = $r->validate($entry);
            self::assertNotSame([], $errors, $name);
            self::assertStringContainsString($needle, implode(' ', $errors), $name);
        }

        \WpStub::addPost(['ID' => 50, 'post_type' => 'page', 'post_name' => 'de', 'post_title' => 'De']);
        self::assertStringContainsString('top-level page "de"', implode(' ', $r->validate(['prefix' => 'de', 'hreflang' => 'de'])));

        $GLOBALS['wp_rewrite']->rules = ['^es/archive/?$' => 'index.php?pagename=archive'];
        self::assertStringContainsString('rewrite rule "^es/archive/?$"', implode(' ', $r->validate(['prefix' => 'es', 'hreflang' => 'es'])));
    }

    /**
     * What else lives at /prefix/ depends on the permalink structure: a
     * post under /%postname%/, a category under /%category%/, a fixed first
     * word. Each would be swallowed by the language route.
     */
    public function testPermalinkStructureCollisions(): void
    {
        $r = $this->plugin->registry;
        \WpStub::addPost(['ID' => 60, 'post_type' => 'post', 'post_name' => 'it', 'post_title' => 'It']);
        self::assertStringContainsString('the post "it" (ID 60)', implode(' ', $r->validate(['prefix' => 'it', 'hreflang' => 'it', 'scripts' => ['Latin']])));

        \WpStub::$options['permalink_structure'] = '/%category%/%postname%/';
        self::assertSame([], $r->validate(['prefix' => 'it', 'hreflang' => 'it', 'scripts' => ['Latin']]), 'A post slug is not at /it/ under this structure.');
        \WpStub::$terms['category']['nl'] = 12;
        self::assertStringContainsString('the category "nl" (ID 12)', implode(' ', $r->validate(['prefix' => 'nl', 'hreflang' => 'nl', 'scripts' => ['Latin']])));

        \WpStub::$options['permalink_structure'] = '/sv/%postname%/';
        self::assertStringContainsString('permalink structure, which starts with "sv/"', implode(' ', $r->validate(['prefix' => 'sv', 'hreflang' => 'sv', 'scripts' => ['Latin']])));
    }

    /** Test 2 (case 3). */
    public function testNoScriptsNeedsExplicitOptOut(): void
    {
        $r = $this->plugin->registry;
        $errors = $r->validate(['prefix' => 'tlh', 'hreflang' => 'tlh']);
        self::assertStringContainsString('no script guard', implode(' ', $errors));
        self::assertSame([], $r->validate(['prefix' => 'tlh', 'hreflang' => 'tlh', 'no_script_guard' => true]));
    }

    /** Test 3 (case 4). */
    public function testRemovingStopsRoutesHeadAndSitemapButKeepsRows(): void
    {
        $this->language('fr', ['publish' => true, 'index' => true, 'scripts' => ['Latin']]);
        $this->post(10);
        $this->live(10, 'fr');
        self::assertNotSame([], $this->plugin->translations->cluster(10));
        self::assertArrayHasKey('fr', $this->plugin->sitemaps->languages());

        self::assertTrue($this->plugin->registry->remove('fr'));
        $this->plugin->translations->forget(10);
        self::assertSame(404, $this->plugin->router->decide('fr', 'council-approves-budget-10')->status);
        self::assertSame([], $this->plugin->translations->cluster(10));
        self::assertSame([], $this->plugin->sitemaps->languages());
        self::assertCount(1, $this->store->rowsFor(10, 'fr', [Row::LIVE]));

        self::assertSame(1, $this->store->deleteForLang('fr'));
        self::assertSame([], $this->store->rowsFor(10, 'fr'));
    }

    /** Test 4 (cases 5, 12). */
    public function testSourceLanguageDefaultsToLocaleAndPostsCanOverride(): void
    {
        \WpStub::$locale = 'en_GB';
        $this->plugin->settings->reset();
        self::assertSame('en-GB', $this->plugin->settings->sourceLanguage());
        $this->plugin->settings->save(['source_lang' => 'en']);
        self::assertSame('en', $this->plugin->settings->sourceLanguage());

        $this->language('fr', ['scripts' => ['Latin']]);
        $post = $this->post(11);
        self::assertSame('en', $this->plugin->posts->sourceLanguage($post));
        update_post_meta(11, Posts::META_SOURCE_LANG, 'fr');
        self::assertSame('fr', $this->plugin->posts->sourceLanguage($post));
        self::assertSame('fr', $this->plugin->posts->nativeLanguage($post)?->prefix);
    }

    /** Test 5 (cases 6, 1). */
    public function testSavingSchedulesExactlyOneFlush(): void
    {
        $r = $this->plugin->registry;
        $r->save(['prefix' => 'fr', 'hreflang' => 'fr']);
        $r->save(['prefix' => 'de', 'hreflang' => 'de']);
        self::assertSame(1, get_option(Registry::FLUSH_OPTION));
        self::assertTrue($r->maybeFlush());
        self::assertFalse($r->maybeFlush());
        self::assertSame(1, \WpStub::$flushes);

        $fr = $r->get('fr');
        self::assertNotNull($fr);
        self::assertFalse($fr->publish);
        self::assertFalse($fr->index);
        self::assertFalse($fr->auto);
        self::assertSame(['Latin'], $fr->scripts);
        self::assertSame('ltr', $fr->dir);
    }

    public function testLanguagesFilterAddsEntries(): void
    {
        add_filter('translate_sidecar_languages', static function (array $langs): array {
            $langs[] = ['prefix' => 'ar', 'hreflang' => 'ar', 'dir' => 'rtl'];
            $langs[] = ['prefix' => 'BAD!', 'hreflang' => 'x'];

            return $langs;
        });
        $this->plugin->registry->reset();
        $all = $this->plugin->registry->all();
        self::assertSame(['ar'], array_keys($all));
        self::assertSame('rtl', $all['ar']->dir);
        self::assertSame(['Arabic'], $all['ar']->scripts);
    }
}
