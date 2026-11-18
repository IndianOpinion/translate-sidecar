<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Admin;

use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Storage\Row;

/**
 * The numbers an operator needs: counts by state per language, stale
 * translations, today's usage against the cap, switches, failures and the
 * next tick. Shared by the settings screen and `wp translate-sidecar status`.
 */
final class Dashboard
{
    public const STALE_SAMPLE = 500;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $onlyLang = null): array
    {
        $p = $this->plugin;
        $counts = $p->store->stateCounts();
        $langs = [];
        foreach ($p->registry->routable() as $prefix => $lang) {
            if ($onlyLang !== null && $onlyLang !== $prefix) {
                continue;
            }
            $c = $counts[$prefix] ?? [];
            $langs[$prefix] = [
                'publish' => $lang->publish,
                'index' => $lang->index,
                'auto' => $lang->auto,
                'queued' => ($c[Row::QUEUED] ?? 0) + ($c[Row::RUNNING] ?? 0),
                'failed' => $c[Row::FAILED] ?? 0,
                'candidate' => $c[Row::CANDIDATE] ?? 0,
                'live' => $c[Row::LIVE] ?? 0,
                'withdrawn' => $c[Row::WITHDRAWN] ?? 0,
                'stale' => $this->staleCount($prefix),
            ];
        }
        $day = $p->clock->localDay();
        $failures = [];
        foreach ($p->store->recentFailures(20) as $row) {
            $failures[] = ['id' => $row->id, 'post_id' => $row->postId, 'lang' => $row->lang, 'reason' => $row->reason, 'when' => $row->updatedGmt];
        }
        $next = wp_next_scheduled(Plugin::TICK_HOOK);

        return [
            'languages' => $langs,
            'today' => [
                'day' => $day,
                'calls' => $p->store->counterGet($day, 'calls'),
                'call_cap' => $p->settings->dailyCap(),
                'chars' => $p->store->counterGet($day, 'chars'),
                'char_cap' => $p->settings->int('daily_chars'),
            ],
            'enabled' => $p->settings->isEnabled(),
            'credit_paused_until' => $p->settings->creditPausedUntil(),
            'failures' => $failures,
            'next_tick' => is_int($next) ? gmdate('Y-m-d H:i:s', $next) : '',
            'last_tick' => (int) get_option('translate_sidecar_last_tick', 0),
            'head_mode' => $p->head->mode(),
            'schema_ok' => $p->store->isWritable(),
        ];
    }

    /**
     * Live rows whose original changed since they were made, from a
     * sample of the newest rows.
     */
    public function staleCount(string $lang): int
    {
        $p = $this->plugin;
        $rows = $p->store->liveForLang($lang, 0, self::STALE_SAMPLE);
        $stale = 0;
        foreach ($rows as $row) {
            $post = $p->posts->get($row->postId);
            if ($post !== null && $p->translations->isStale($row, $post)) {
                $stale++;
            }
        }

        return $stale;
    }

    public function render(): void
    {
        $d = $this->data();
        echo '<h2>' . esc_html('Status') . '</h2>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html('Language') . '</th>';
        foreach (['publish', 'index', 'auto', 'queued', 'failed', 'candidate', 'live', 'withdrawn', 'stale'] as $h) {
            echo '<th>' . esc_html($h) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($d['languages'] as $prefix => $row) {
            echo '<tr><td>' . esc_html((string) $prefix) . '</td>';
            foreach ($row as $value) {
                echo '<td>' . esc_html(is_bool($value) ? ($value ? 'on' : 'off') : (string) $value) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        $t = $d['today'];
        echo '<p>' . esc_html(sprintf(
            'Today (%s): %d of %d calls, %d characters%s. Calls are %s.%s Next tick: %s UTC. Head mode: %s.',
            $t['day'],
            $t['calls'],
            $t['call_cap'],
            $t['chars'],
            $t['char_cap'] > 0 ? ' of ' . $t['char_cap'] : '',
            $d['enabled'] ? 'allowed' : 'stopped by the kill switch',
            $d['credit_paused_until'] > time() ? ' Credit pause until ' . gmdate('Y-m-d H:i', (int) $d['credit_paused_until']) . ' UTC.' : '',
            $d['next_tick'] !== '' ? $d['next_tick'] : 'not scheduled',
            $d['head_mode']
        )) . '</p>';
        if ($d['failures'] !== []) {
            echo '<h3>' . esc_html('Last 20 failures') . '</h3><ul>';
            foreach ($d['failures'] as $f) {
                echo '<li>' . esc_html(sprintf('#%d post %d %s: %s at %s', $f['id'], $f['post_id'], $f['lang'], $f['reason'], (string) $f['when'])) . '</li>';
            }
            echo '</ul>';
        }
        $sample = $this->sampleHead();
        if ($sample !== '') {
            echo '<h3>' . esc_html('Tags on a sample translation') . '</h3><pre>' . esc_html($sample) . '</pre>';
        }
    }

    /**
     * The exact tags that will be printed on a sample translation page.
     */
    public function sampleHead(): string
    {
        $p = $this->plugin;
        foreach (array_keys($p->registry->published()) as $prefix) {
            $rows = $p->store->liveForLang($prefix, 0, 1);
            if ($rows === []) {
                continue;
            }
            $row = $p->store->find($rows[0]->id);
            if ($row === null) {
                continue;
            }
            $saved = $p->router->decision();
            $p->router->setDecision(new \IndianOpinion\TranslateSidecar\Front\Decision(200, $prefix, $row->postId, null, $row, 'sample'));
            try {
                $values = $p->head->values($row->postId, $prefix);
                $html = $p->head->hreflangLinks($row->postId);

                return $html . (string) wp_json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } finally {
                $p->router->setDecision($saved);
            }
        }

        return '';
    }
}
