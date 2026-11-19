<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Cli;

use IndianOpinion\TranslateSidecar\Admin\Dashboard;
use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\Schema;

/**
 * Manage post translations.
 *
 * Exit codes: 0 ok, 1 error, 2 refused by the cap, the kill switch or the
 * spend meter.
 */
final class Command
{
    public const OK = 0;
    public const ERROR = 1;
    public const REFUSED = 2;

    /** @var callable(string): void */
    private $out;
    /** @var callable(int): void */
    private $exit;

    public function __construct(private readonly Plugin $plugin, ?callable $out = null, ?callable $exit = null)
    {
        $this->out = $out ?? static function (string $line): void {
            \WP_CLI::line($line);
        };
        $this->exit = $exit ?? static function (int $code): void {
            if ($code !== 0) {
                \WP_CLI::halt($code);
            }
        };
    }

    private function line(string $s): void
    {
        ($this->out)($s);
    }

    private function done(int $code): int
    {
        ($this->exit)($code);

        return $code;
    }

    /**
     * Lists or validates registered languages.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (default) or validate.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function languages(array $args = [], array $assoc = []): int
    {
        $action = $args[0] ?? 'list';
        $bad = 0;
        foreach ($this->plugin->registry->all() as $prefix => $lang) {
            $line = sprintf('%s hreflang=%s locale=%s dir=%s scripts=%s publish=%s index=%s auto=%s', $prefix, $lang->hreflang, $lang->locale, $lang->dir, $lang->noScriptGuard ? 'none' : implode('+', $lang->scripts), $lang->publish ? 'on' : 'off', $lang->index ? 'on' : 'off', $lang->auto ? 'on' : 'off');
            if ($action === 'validate') {
                $collisions = $this->plugin->registry->collisions($prefix);
                if ($collisions !== []) {
                    $bad++;
                    $line .= ' COLLISION: ' . implode('; ', $collisions);
                }
            }
            $this->line($line);
        }

        return $this->done($bad > 0 ? self::ERROR : self::OK);
    }

    /**
     * Requests translations.
     *
     * ## OPTIONS
     *
     * <post-id>...
     * : One or more post ids.
     *
     * --lang=<xx>
     * : Comma-separated prefixes.
     *
     * [--replace]
     * : Replace a waiting candidate once the new one is ready.
     *
     * [--dry-run]
     * : Report segments, calls and the cap; write nothing.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function request(array $args = [], array $assoc = []): int
    {
        $langs = array_values(array_filter(array_map('trim', explode(',', (string) ($assoc['lang'] ?? '')))));
        if ($args === [] || $langs === []) {
            $this->line('Usage: wp translate-sidecar request <post-id>... --lang=<xx>[,<yy>] [--replace] [--dry-run]');

            return $this->done(self::ERROR);
        }
        $code = self::OK;
        foreach ($args as $id) {
            $result = $this->plugin->requester->request((int) $id, $langs, [
                'replace' => !empty($assoc['replace']),
                'dry_run' => !empty($assoc['dry-run']),
                'source' => 'cli',
            ]);
            foreach ($result as $lang => $r) {
                $this->line(sprintf('post %d %s: %s', (int) $id, $lang, (string) wp_json_encode($r)));
                if (($r['status'] ?? '') === 'refused') {
                    $code = self::ERROR;
                }
                if (($r['status'] ?? '') === 'dry_run' && empty($r['cap_allows']) && $code === self::OK) {
                    $code = self::REFUSED;
                }
            }
        }

        return $this->done($code);
    }

    /**
     * Runs one tick.
     *
     * ## OPTIONS
     *
     * [--max=<n>]
     * : Jobs to process.
     *
     * [--time=<s>]
     * : Time budget in seconds.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function run(array $args = [], array $assoc = []): int
    {
        $opts = [];
        if (isset($assoc['max'])) {
            $opts['max'] = (int) $assoc['max'];
        }
        if (isset($assoc['time'])) {
            $opts['time'] = (int) $assoc['time'];
        }
        if (!$this->plugin->store->isWritable()) {
            $this->line('Schema not verified; run "wp translate-sidecar verify-schema".');

            return $this->done(self::ERROR);
        }
        $summary = $this->plugin->runner->tick($opts);
        $this->line((string) wp_json_encode($summary->toArray()));
        if (in_array($summary->stopped, ['disabled', 'capped', 'spend_denied', 'credit_paused'], true)) {
            return $this->done(self::REFUSED);
        }

        return $this->done(self::OK);
    }

    /**
     * Prints the dashboard numbers.
     *
     * ## OPTIONS
     *
     * [--lang=<xx>]
     * : One language only.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function status(array $args = [], array $assoc = []): int
    {
        $data = (new Dashboard($this->plugin))->data(isset($assoc['lang']) ? (string) $assoc['lang'] : null);
        $this->line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->done(self::OK);
    }

    /**
     * Approves a candidate.
     *
     * ## OPTIONS
     *
     * <row-id>
     * : Row id.
     *
     * [--with-warnings]
     * : Approve despite guard warnings.
     *
     * [--reason=<text>]
     * : Required with --with-warnings.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function approve(array $args = [], array $assoc = []): int
    {
        $r = $this->plugin->review->approve((int) ($args[0] ?? 0), (int) get_current_user_id(), [
            'with_warnings' => !empty($assoc['with-warnings']),
            'reason' => (string) ($assoc['reason'] ?? ''),
        ]);

        return $this->report($r, 'Approved.');
    }

    /**
     * Rejects a candidate.
     *
     * ## OPTIONS
     *
     * <row-id>
     * : Row id.
     *
     * [--reason=<text>]
     * : Reason.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function reject(array $args = [], array $assoc = []): int
    {
        return $this->report($this->plugin->review->reject((int) ($args[0] ?? 0), (int) get_current_user_id(), (string) ($assoc['reason'] ?? '')), 'Rejected.');
    }

    /**
     * Withdraws a live translation; its URL answers 410.
     *
     * ## OPTIONS
     *
     * <row-id>
     * : Row id.
     *
     * @param list<string> $args
     */
    public function withdraw(array $args = []): int
    {
        return $this->report($this->plugin->review->withdraw((int) ($args[0] ?? 0), (int) get_current_user_id()), 'Withdrawn.');
    }

    /**
     * Restores the newest prior version.
     *
     * ## OPTIONS
     *
     * <post-id>
     * : Post id.
     *
     * --lang=<xx>
     * : Language prefix.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function rollback(array $args = [], array $assoc = []): int
    {
        return $this->report($this->plugin->review->rollback((int) ($args[0] ?? 0), (string) ($assoc['lang'] ?? ''), (int) get_current_user_id()), 'Rolled back.');
    }

    /**
     * Prints the stored guard report of a row.
     *
     * ## OPTIONS
     *
     * <row-id>
     * : Row id.
     *
     * @param list<string> $args
     */
    public function guard(array $args = []): int
    {
        $row = $this->plugin->store->find((int) ($args[0] ?? 0));
        if ($row === null) {
            $this->line('No such row.');

            return $this->done(self::ERROR);
        }
        $report = GuardReport::fromArray($row->guardReport);
        $this->line((string) wp_json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->done($report->passed() ? self::OK : self::ERROR);
    }

    /**
     * Rebuilds sitemaps.
     *
     * ## OPTIONS
     *
     * <action>
     * : rebuild
     *
     * [--lang=<xx>]
     * : One language only.
     *
     * @param list<string> $args
     * @param array<string, string> $assoc
     */
    public function sitemap(array $args = [], array $assoc = []): int
    {
        if (($args[0] ?? '') !== 'rebuild') {
            $this->line('Usage: wp translate-sidecar sitemap rebuild [--lang=<xx>]');

            return $this->done(self::ERROR);
        }
        $langs = isset($assoc['lang']) ? [(string) $assoc['lang']] : array_keys($this->plugin->sitemaps->languages());
        $this->plugin->sitemapCache->markDirty($langs);
        foreach ($langs as $lang) {
            $pages = $this->plugin->sitemaps->maxPages($lang);
            $urls = 0;
            for ($p = 1; $p <= $pages; $p++) {
                $urls += count($this->plugin->sitemaps->entries($lang, $p));
            }
            $this->line(sprintf('%s: %d pages, %d URLs', $lang, $pages, $urls));
        }

        return $this->done(self::OK);
    }

    /**
     * Compares the tables with the expected columns. Exits 1 on a mismatch.
     */
    public function verify_schema(): int // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WP-CLI subcommand name.
    {
        $schema = $this->plugin->schema;
        if ($schema === null) {
            $this->line('No database schema in use.');

            return $this->done(self::ERROR);
        }
        $missing = $schema->verify();
        if ($missing !== []) {
            $this->line('Schema mismatch: ' . Schema::describe($missing));

            return $this->done(self::ERROR);
        }
        $this->line('Schema verified.');

        return $this->done(self::OK);
    }

    /**
     * Deletes every row of a language.
     *
     * ## OPTIONS
     *
     * <xx>
     * : Language prefix.
     *
     * --yes
     * : Confirm.
     *
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     */
    public function purge_language(array $args = [], array $assoc = []): int // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- WP-CLI subcommand name.
    {
        $lang = (string) ($args[0] ?? '');
        if ($lang === '' || empty($assoc['yes'])) {
            $this->line('Usage: wp translate-sidecar purge-language <xx> --yes');

            return $this->done(self::ERROR);
        }
        $n = $this->plugin->store->deleteForLang($lang);
        $this->plugin->sitemapCache->markDirty([$lang]);
        $this->line(sprintf('Purged %d rows.', $n));

        return $this->done(self::OK);
    }

    private function report(bool|\WP_Error $r, string $ok): int
    {
        if ($r instanceof \WP_Error) {
            $this->line('Error: ' . $r->get_error_message());

            return $this->done(self::ERROR);
        }
        $this->line($ok);

        return $this->done(self::OK);
    }
}
