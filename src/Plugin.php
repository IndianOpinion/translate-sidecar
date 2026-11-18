<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar;

use IndianOpinion\TranslateSidecar\Admin\Admin;
use IndianOpinion\TranslateSidecar\Admin\SiteHealth;
use IndianOpinion\TranslateSidecar\Cli\Command;
use IndianOpinion\TranslateSidecar\Front\Frontend;
use IndianOpinion\TranslateSidecar\Front\Router;
use IndianOpinion\TranslateSidecar\Head\Head;
use IndianOpinion\TranslateSidecar\Head\StructuredData;
use IndianOpinion\TranslateSidecar\Job\Gate;
use IndianOpinion\TranslateSidecar\Job\GuardsFactory;
use IndianOpinion\TranslateSidecar\Job\Requester;
use IndianOpinion\TranslateSidecar\Job\Runner;
use IndianOpinion\TranslateSidecar\Language\Registry;
use IndianOpinion\TranslateSidecar\Rest\Controller;
use IndianOpinion\TranslateSidecar\Review\Review;
use IndianOpinion\TranslateSidecar\Sitemap\Cache;
use IndianOpinion\TranslateSidecar\Sitemap\CoreProvider;
use IndianOpinion\TranslateSidecar\Sitemap\Sitemaps;
use IndianOpinion\TranslateSidecar\Storage\Schema;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Storage\WpdbStore;
use IndianOpinion\TranslateSidecar\Support\Clock;
use IndianOpinion\TranslateSidecar\Support\Log;
use IndianOpinion\TranslateSidecar\Translator\Translators;

/**
 * Service container and hook wiring. One instance per request (per site on
 * multisite, see reset()).
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const TICK_HOOK = 'translate_sidecar_tick';
    public const DAILY_HOOK = 'translate_sidecar_daily';

    private static ?self $instance = null;

    public readonly Settings $settings;
    public readonly Registry $registry;
    public readonly Clock $clock;
    public readonly Store $store;
    public readonly ?Schema $schema;
    public readonly Posts $posts;
    public readonly Translations $translations;
    public readonly GuardsFactory $guards;
    public readonly Gate $gate;
    public readonly Translators $translators;
    public readonly Requester $requester;
    public readonly Runner $runner;
    public readonly Cache $sitemapCache;
    public readonly Purger $purger;
    public readonly Review $review;
    public readonly Router $router;
    public readonly Frontend $frontend;
    public readonly StructuredData $structuredData;
    public readonly Head $head;
    public readonly Sitemaps $sitemaps;
    public readonly Lifecycle $lifecycle;

    public function __construct(?Store $store = null, ?Clock $clock = null, ?Schema $schema = null)
    {
        $this->settings = new Settings();
        $this->registry = new Registry($this->settings);
        $this->clock = $clock ?? new Clock();
        if ($store === null) {
            global $wpdb;
            $schema ??= new Schema($wpdb);
            $store = new WpdbStore($wpdb, $schema);
        }
        $this->schema = $schema;
        $this->store = $store;
        $this->guards = new GuardsFactory($this->settings);
        $this->posts = new Posts($this->settings, $this->registry, $this->guards->counter());
        $this->translations = new Translations($this->store, $this->registry, $this->settings, $this->posts);
        $this->gate = new Gate($this->settings, $this->store, $this->clock);
        $this->translators = new Translators($this->settings);
        $this->requester = new Requester($this->store, $this->registry, $this->settings, $this->posts, $this->gate, $this->clock);
        $this->runner = new Runner($this->store, $this->registry, $this->settings, $this->posts, $this->gate, $this->translators, $this->guards, $this->clock);
        $this->sitemapCache = new Cache($this->store, $this->clock);
        $this->purger = new Purger($this->translations, $this->sitemapCache);
        $this->review = new Review($this->store, $this->registry, $this->settings, $this->posts, $this->translations, $this->purger, $this->guards, $this->clock);
        $this->router = new Router($this->registry, $this->settings, $this->posts, $this->translations, $this->store);
        $this->frontend = new Frontend($this->router, $this->translations, $this->registry, $this->posts, $this->settings, $this->store);
        $this->structuredData = new StructuredData($this->router, $this->translations, $this->registry, $this->posts, $this->frontend);
        $this->head = new Head($this->router, $this->translations, $this->registry, $this->posts, $this->settings, $this->frontend, $this->structuredData);
        $this->sitemaps = new Sitemaps($this->registry, $this->settings, $this->translations, $this->posts, $this->store, $this->sitemapCache, $this->clock);
        $this->lifecycle = new Lifecycle($this->store, $this->registry, $this->settings, $this->posts, $this->translations, $this->requester, $this->purger, $this->sitemapCache, $this->clock);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replaces the instance; used by tests and on switch_blog.
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    /**
     * Hooks everything. Called once on plugins_loaded.
     */
    public function boot(): void
    {
        add_action('init', [$this, 'onInit'], 5);
        add_filter('query_vars', [$this->router, 'queryVars']);
        add_filter('request', [$this->router, 'onRequest'], 5);
        add_action('template_redirect', [$this, 'onTemplateRedirect'], 0);
        add_filter('redirect_canonical', [$this->router, 'filterRedirectCanonical'], 5);
        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action(self::TICK_HOOK, [$this, 'onTick']);
        add_action(self::DAILY_HOOK, [$this->lifecycle, 'daily']);
        add_action('translate_sidecar_run_job', [$this, 'onRunJob']);
        add_filter('robots_txt', [$this->sitemaps, 'robotsTxt'], 20, 2);
        add_action('wp_sitemaps_init', [$this, 'onSitemapsInit']);
        add_action('switch_blog', static function (): void {
            self::setInstance(null);
        });
        $this->frontend->register();
        $this->head->register();
        $this->structuredData->register();
        $this->lifecycle->register();
        if (is_admin()) {
            (new Admin($this))->register();
        }
        (new SiteHealth($this))->register();
        add_action('rest_api_init', [new Controller($this), 'register']);
        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('translate-sidecar', new Command($this));
        }
    }

    public function onInit(): void
    {
        if ($this->schema !== null && $this->schema->needsUpgrade() && is_admin()) {
            try {
                $this->schema->install();
            } catch (\Throwable $e) {
                Log::error('schema upgrade', $e);
            }
        }
        $this->router->addRewriteRules();
        foreach ($this->sitemaps->rules() as $regex => $query) {
            add_rewrite_rule($regex, $query, 'top');
        }
        $this->registry->maybeFlush();
        $this->ensureSchedules();
        if (function_exists('register_block_type')) {
            register_block_type('translate-sidecar/language-switcher', [
                'api_version' => '3',
                'title' => 'Translations',
                'category' => 'widgets',
                'render_callback' => static fn (): string => translate_sidecar_language_switcher(),
            ]);
        }
    }

    public function onTemplateRedirect(): void
    {
        $sitemap = get_query_var(Router::QV_SITEMAP);
        if (is_string($sitemap) && $sitemap !== '') {
            $response = $this->sitemaps->response($sitemap);
            status_header($response['status']);
            foreach ($response['headers'] as $name => $value) {
                header($name . ': ' . $value);
            }
            if ($response['status'] === 404) {
                global $wp_query;
                $wp_query->set_404();

                return;
            }
            echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- XML built from escaped parts.
            exit;
        }
        if ($this->router->respond()) {
            exit;
        }
    }

    public function onSitemapsInit(mixed $server = null): void
    {
        if (!class_exists('WP_Sitemaps_Provider') || !function_exists('wp_register_sitemap_provider')) {
            return;
        }
        wp_register_sitemap_provider('translations', new CoreProvider($this->sitemaps));
    }

    /**
     * @param mixed $schedules
     * @return mixed
     */
    public function cronSchedules(mixed $schedules): mixed
    {
        if (!is_array($schedules)) {
            return $schedules;
        }
        $interval = $this->settings->int('tick_interval');
        $schedules['translate_sidecar_tick'] = ['interval' => $interval, 'display' => sprintf('Every %d seconds (translate-sidecar)', $interval)];

        return $schedules;
    }

    public function ensureSchedules(): void
    {
        if (!wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event($this->clock->now() + 60, 'translate_sidecar_tick', self::TICK_HOOK);
        }
        if (!wp_next_scheduled(self::DAILY_HOOK)) {
            wp_schedule_event($this->clock->now() + 3600, 'daily', self::DAILY_HOOK);
        }
    }

    public function onTick(): void
    {
        if (!$this->store->isWritable()) {
            return;
        }
        $this->runner->tick();
    }

    public function onRunJob(mixed $jobId): void
    {
        if ($this->store->isWritable()) {
            $this->runner->runById((int) $jobId);
        }
    }

    /**
     * Activation for one site: tables, verification, schedules, a rewrite
     * flush on the next request.
     */
    public static function activateSite(): void
    {
        $plugin = new self();
        if ($plugin->schema !== null) {
            try {
                $plugin->schema->install();
            } catch (\Throwable $e) {
                Log::error('activation', $e);
            }
        }
        $plugin->registry->scheduleFlush();
        $plugin->ensureSchedules();
    }

    public static function activate(mixed $networkWide = false): void
    {
        if (is_multisite() && $networkWide && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::activateSite();
                restore_current_blog();
            }

            return;
        }
        self::activateSite();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::TICK_HOOK);
        wp_clear_scheduled_hook(self::DAILY_HOOK);
        flush_rewrite_rules(false);
    }

    /**
     * Capability for an action: request, review or settings.
     */
    public static function capability(string $action): string
    {
        $defaults = ['request' => 'edit_others_posts', 'review' => 'edit_others_posts', 'settings' => 'manage_options'];

        return (string) apply_filters('translate_sidecar_capability', $defaults[$action] ?? 'manage_options', $action);
    }

    public static function can(string $action): bool
    {
        return current_user_can(self::capability($action));
    }
}
