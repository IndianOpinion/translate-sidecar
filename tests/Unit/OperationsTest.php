<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Admin\Actions;
use IndianOpinion\TranslateSidecar\Admin\SiteHealth;
use IndianOpinion\TranslateSidecar\Cli\Command;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Rest\Controller;
use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Schema;
use IndianOpinion\TranslateSidecar\Storage\SchemaException;
use IndianOpinion\TranslateSidecar\Storage\WpdbStore;
use IndianOpinion\TranslateSidecar\Tests\Support\TestCase;
use IndianOpinion\TranslateSidecar\Uninstaller;

/**
 * Acceptance tests 46, 79, 82, 84, 85 and 86.
 */
final class OperationsTest extends TestCase
{
    /** Test 46 (case 51). */
    public function testReadmeSaysScriptIsNotLanguage(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        self::assertStringContainsString('The script guard proves the script, not the language.', $readme);
        self::assertStringContainsString('Written from scratch; not derived from any third-party plugin.', $readme);
    }

    /** Test 79 (case 93). */
    public function testLaterPageAtPrefixIsFlagged(): void
    {
        $this->language('fr', ['scripts' => ['Latin']]);
        $health = new SiteHealth($this->plugin);
        self::assertSame('good', $health->run('collisions')['status']);
        \WpStub::addPost(['ID' => 90, 'post_type' => 'page', 'post_name' => 'fr', 'post_title' => 'Fr']);
        $r = $health->run('collisions');
        self::assertSame('recommended', $r['status']);
        self::assertStringContainsString('"fr"', $r['label']);
        self::assertArrayNotHasKey('^fr/?$', $this->plugin->router->rules(), 'The page at /fr/ still renders.');
        self::assertArrayHasKey('^fr/(.+?)/?$', $this->plugin->router->rules());
    }

    /** Test 82 (case 100). */
    public function testMultisiteIsPerSiteWithNetworkKillSwitch(): void
    {
        \WpStub::$multisite = true;
        $this->plugin->settings->save(['daily_cap' => 9]);
        $this->store->counterAdd($this->clock->localDay(), 'calls', 3);
        switch_to_blog(2);
        $site2 = new Plugin(new \IndianOpinion\TranslateSidecar\Storage\MemoryStore(), $this->clock);
        self::assertSame(100, $site2->settings->dailyCap());
        self::assertSame(100, $site2->gate->remaining());
        self::assertTrue($site2->settings->isEnabled());
        update_site_option(Settings::NETWORK_KILL_OPTION, 1);
        self::assertFalse($site2->settings->isEnabled());
        restore_current_blog();
        $this->plugin->settings->reset();
        self::assertSame(9, $this->plugin->settings->dailyCap());
        self::assertSame(6, $this->plugin->gate->remaining());
        self::assertFalse($this->plugin->settings->isEnabled());
    }

    /** Test 84 (section 6). */
    public function testUninstallOnEverySite(): void
    {
        global $wpdb;
        $wpdb = new \FakeWpdb();
        \WpStub::$multisite = true;
        \WpStub::$blogs[2] = ['options' => []];
        \WpStub::$scheduled = [Plugin::TICK_HOOK => 1, Plugin::DAILY_HOOK => 2];
        update_site_option(Settings::NETWORK_KILL_OPTION, 1);
        Uninstaller::run();
        $drops = array_values(array_filter($wpdb->queries, static fn (string $q): bool => str_starts_with($q, 'DROP TABLE')));
        self::assertCount(6, $drops, 'Three tables on each of two sites.');
        self::assertCount(2, array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'wp_options WHERE option_name LIKE') && str_contains($q, 'translate\\_sidecar\\_%')));
        self::assertCount(2, array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'wp_postmeta WHERE meta_key LIKE')));
        self::assertSame([], \WpStub::$scheduled);
        self::assertSame([], \WpStub::$siteOptions);
    }

    /** Test 85 (section 8). */
    public function testEveryAdminActionNeedsCapabilityAndNonce(): void
    {
        $actions = new Actions($this->plugin);
        foreach (Actions::CAPS as $action => $group) {
            \WpStub::$currentUser = 1;
            $r = $actions->handle($action, ['_wpnonce' => 'bad']);
            self::assertFalse($r['ok'], $action . ' without a valid nonce');
            self::assertStringContainsString('expired', $r['message']);
            $r = $actions->handle($action, []);
            self::assertFalse($r['ok'], $action . ' without a nonce');

            \WpStub::$currentUser = 3;
            $r = $actions->handle($action, ['_wpnonce' => wp_create_nonce(Actions::nonceAction($action))]);
            self::assertFalse($r['ok'], $action . ' without the capability');
            self::assertStringContainsString('not allowed', $r['message']);
        }
        \WpStub::$currentUser = 2;
        $r = $actions->handle('save_settings', ['_wpnonce' => wp_create_nonce('translate_sidecar_save_settings')]);
        self::assertFalse($r['ok'], 'Editors cannot change settings.');

        \WpStub::$currentUser = 3;
        self::assertSame('forbidden', translate_sidecar_approve(1) instanceof \WP_Error ? 'forbidden' : '');
        self::assertSame('forbidden', translate_sidecar_withdraw(1) instanceof \WP_Error ? 'forbidden' : '');
        self::assertSame('forbidden', translate_sidecar_request(1, ['fr'])['fr']['reason']);

        $routes = [];
        $GLOBALS['__routes'] = &$routes;
        \WpStub::$currentUser = 3;
        $controller = new Controller($this->plugin);
        $controller->register();
        foreach ($routes as $route => $args) {
            $list = isset($args['methods']) ? [$args] : $args;
            foreach ($list as $def) {
                $allowed = ($def['permission_callback'])();
                if ($def['methods'] === 'GET' && str_contains($route, '/posts/')) {
                    self::assertTrue($allowed, 'The public list is open.');
                } else {
                    self::assertFalse($allowed, $route . ' ' . $def['methods']);
                }
            }
        }
        self::assertGreaterThanOrEqual(6, count($routes));
    }

    /** Test 86 (cases 103 to 106). */
    public function testSchemaVerification(): void
    {
        global $wpdb;
        $wpdb = new \FakeWpdb();
        $GLOBALS['dbdelta_skip'] = ['created_by'];
        $schema = new Schema($wpdb);
        self::assertFalse($schema->install());
        self::assertNotSame(Schema::VERSION, get_option(Schema::VERSION_OPTION, ''));
        self::assertSame(['wp_translate_sidecar_rows' => ['created_by']], get_option(Schema::ERROR_OPTION));
        $store = new WpdbStore($wpdb, $schema);
        self::assertFalse($store->isWritable());
        try {
            $store->insert(new \IndianOpinion\TranslateSidecar\Storage\Row());
            self::fail('A write must be refused.');
        } catch (SchemaException $e) {
            self::assertStringContainsString('verify-schema', $e->getMessage());
        }
        $plugin = new Plugin($store, $this->clock, $schema);
        $lines = [];
        $code = null;
        $cli = new Command($plugin, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        }, static function (int $c) use (&$code): void {
            $code = $c;
        });
        self::assertSame(1, $cli->verify_schema());
        self::assertSame(1, $code);
        self::assertStringContainsString('wp_translate_sidecar_rows', $lines[0]);
        self::assertStringContainsString('created_by', $lines[0]);
        \WpStub::$currentUser = 1;
        \WpStub::$admin = true;
        ob_start();
        (new \IndianOpinion\TranslateSidecar\Admin\Admin($plugin))->notices();
        self::assertStringContainsString('created_by', (string) ob_get_clean());
        self::assertSame('critical', (new SiteHealth($plugin))->run('schema')['status']);

        $GLOBALS['dbdelta_skip'] = [];
        self::assertTrue($schema->install());
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        self::assertSame(0, $cli->verify_schema());
        self::assertTrue((bool) get_option(Schema::UNIQUE_OPTION));
        self::assertTrue((bool) array_filter($wpdb->queries, static fn (string $q): bool => str_contains($q, 'ADD UNIQUE KEY one_per_slot')));

        $wpdb2 = new \FakeWpdb();
        $wpdb2->refuseAlter = true;
        $wpdb = $wpdb2;
        self::assertTrue((new Schema($wpdb2))->install(), 'Without generated columns the code lock alone enforces uniqueness.');
        self::assertSame(0, get_option(Schema::UNIQUE_OPTION));
    }
}
