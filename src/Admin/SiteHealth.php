<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Admin;

use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Routing;
use IndianOpinion\TranslateSidecar\Storage\Schema;

/**
 * Site Health tests: permalinks, prefix collisions, schema, WP-Cron and
 * adapter configuration.
 */
final class SiteHealth
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'tests']);
    }

    /**
     * @param mixed $tests
     * @return mixed
     */
    public function tests(mixed $tests): mixed
    {
        if (!is_array($tests)) {
            return $tests;
        }
        foreach (['permalinks', 'collisions', 'schema', 'cron', 'adapter'] as $name) {
            $tests['direct']['translate_sidecar_' . $name] = [
                'label' => 'translate-sidecar: ' . $name,
                'test' => fn (): array => $this->run($name),
            ];
        }

        return $tests;
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $name): array
    {
        $result = match ($name) {
            'permalinks' => $this->permalinks(),
            'collisions' => $this->collisions(),
            'schema' => $this->schema(),
            'cron' => $this->cron(),
            'adapter' => $this->adapter(),
            default => ['good', 'Unknown test.'],
        };

        return [
            'label' => $result[1],
            'status' => $result[0],
            'badge' => ['label' => 'Translations', 'color' => $result[0] === 'good' ? 'blue' : 'orange'],
            'description' => '<p>' . esc_html($result[1]) . '</p>',
            'actions' => '',
            'test' => 'translate_sidecar_' . $name,
        ];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function permalinks(): array
    {
        return Routing::prettyPermalinks()
            ? ['good', 'Pretty permalinks are on, so translation URLs can route.']
            : ['critical', 'Pretty permalinks are off: translation URLs, hreflang and translation sitemaps are disabled.'];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function collisions(): array
    {
        $found = [];
        // The same checks as saving a language, so a page, post or category
        // created later at a prefix is flagged too (case 93).
        foreach (array_keys($this->plugin->registry->all()) as $prefix) {
            foreach ($this->plugin->registry->collisions($prefix) as $collision) {
                $found[] = sprintf('the language prefix "%s" collides with %s', $prefix, $collision);
            }
        }

        return $found === []
            ? ['good', 'No language prefix collides with a page, a post, a category or a rewrite rule.']
            : ['recommended', 'Prefix collision: ' . implode('; ', $found) . '. The language route wins for /prefix/slug/.'];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function schema(): array
    {
        $error = get_option(Schema::ERROR_OPTION, []);
        if (is_array($error) && $error !== []) {
            return ['critical', 'The translations schema is incomplete: ' . Schema::describe($error) . '. Writes are blocked.'];
        }

        return $this->plugin->store->isWritable()
            ? ['good', 'The translations schema is verified.']
            : ['critical', 'The translations schema version is not recorded. Reactivate the plugin.'];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function cron(): array
    {
        $last = (int) get_option('translate_sidecar_last_tick', 0);
        $interval = $this->plugin->settings->int('tick_interval');
        if ($last === 0) {
            return ['recommended', 'The translation tick has not run yet.'];
        }

        return time() - $last > 3 * $interval
            ? ['recommended', sprintf('The last translation tick ran %d minutes ago, more than three intervals. Check WP-Cron or the system cron.', intdiv(time() - $last, 60))]
            : ['good', 'The translation tick runs on time.'];
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function adapter(): array
    {
        $auto = array_filter($this->plugin->registry->all(), static fn ($l): bool => $l->auto);
        if ($auto === []) {
            return ['good', 'No language translates automatically.'];
        }
        foreach ($auto as $lang) {
            if ($this->plugin->translators->forLanguage($lang) === null) {
                return ['recommended', sprintf('Language "%s" has auto on but no adapter is configured.', $lang->prefix)];
            }
        }

        return ['good', 'Every language with auto on has an adapter.'];
    }
}
