<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Admin;

use IndianOpinion\TranslateSidecar\Plugin;
use IndianOpinion\TranslateSidecar\Settings;

/**
 * Every admin action, behind a nonce and a capability check. Returns a
 * result instead of redirecting so it can be tested.
 */
final class Actions
{
    /** Action name to the capability group it needs. */
    public const CAPS = [
        'save_settings' => 'settings',
        'save_language' => 'settings',
        'remove_language' => 'settings',
        'set_switch' => 'settings',
        'purge_language' => 'settings',
        'kill_switch' => 'settings',
        'request' => 'request',
        'approve' => 'review',
        'reject' => 'review',
        'withdraw' => 'review',
        'rollback' => 'review',
        'manual' => 'review',
    ];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public static function nonceAction(string $action): string
    {
        return 'translate_sidecar_' . $action;
    }

    /**
     * @param array<string, mixed> $in request data (already unslashed)
     * @return array{ok:bool, message:string, data?:mixed}
     */
    public function handle(string $action, array $in): array
    {
        if (!isset(self::CAPS[$action])) {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }
        $nonce = isset($in['_wpnonce']) && is_string($in['_wpnonce']) ? $in['_wpnonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::nonceAction($action))) {
            return ['ok' => false, 'message' => 'The link has expired. Reload the page and try again.'];
        }
        if (!Plugin::can(self::CAPS[$action])) {
            return ['ok' => false, 'message' => 'You are not allowed to do that.'];
        }
        $user = (int) get_current_user_id();
        $p = $this->plugin;
        $str = static fn (string $k): string => isset($in[$k]) && is_scalar($in[$k]) ? trim((string) $in[$k]) : '';
        $int = static fn (string $k): int => isset($in[$k]) && is_scalar($in[$k]) ? (int) $in[$k] : 0;

        switch ($action) {
            case 'save_settings':
                $values = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
                foreach (array_keys(Settings::defaults()) as $key) {
                    if (is_bool(Settings::defaults()[$key]) && !isset($values[$key])) {
                        $values[$key] = false;
                    }
                }
                $p->settings->save($values);
                $p->registry->scheduleFlush();

                return ['ok' => true, 'message' => 'Settings saved.'];
            case 'save_language':
                $entry = isset($in['language']) && is_array($in['language']) ? $in['language'] : [];
                foreach (['publish', 'index', 'auto', 'no_script_guard'] as $flag) {
                    $entry[$flag] = !empty($entry[$flag]);
                }
                $replacing = $str('replacing') !== '' ? $str('replacing') : null;
                $errors = $p->registry->save($entry, $replacing);

                return $errors === []
                    ? ['ok' => true, 'message' => 'Language saved.']
                    : ['ok' => false, 'message' => implode(' ', $errors)];
            case 'remove_language':
                return $p->registry->remove($str('lang'))
                    ? ['ok' => true, 'message' => 'Language removed. Its rows stay until you purge them.']
                    : ['ok' => false, 'message' => 'No such language.'];
            case 'set_switch':
                return $p->registry->setSwitch($str('lang'), $str('switch'), !empty($in['on']))
                    ? ['ok' => true, 'message' => 'Switch updated.']
                    : ['ok' => false, 'message' => 'No such language or switch.'];
            case 'purge_language':
                if ($str('confirm') !== $str('lang') || $str('lang') === '') {
                    return ['ok' => false, 'message' => 'Type the language prefix to confirm the purge.'];
                }
                $n = $p->store->deleteForLang($str('lang'));
                $p->sitemapCache->markDirty([$str('lang')]);

                return ['ok' => true, 'message' => sprintf('Purged %d rows.', $n)];
            case 'kill_switch':
                $p->settings->setKillSwitch(!empty($in['on']));

                return ['ok' => true, 'message' => !empty($in['on']) ? 'Kill switch on: no calls will be made.' : 'Kill switch off.'];
            case 'request':
                $langs = isset($in['langs']) && is_array($in['langs']) ? array_values(array_map('strval', $in['langs'])) : array_filter([$str('lang')]);
                $result = $p->requester->request($int('post_id'), array_values($langs), [
                    'replace' => !empty($in['replace']),
                    'dry_run' => !empty($in['dry_run']),
                    'source' => 'admin',
                    'user_id' => $user,
                ]);
                $parts = [];
                foreach ($result as $lang => $r) {
                    $parts[] = $lang . ': ' . (string) $r['status'] . (isset($r['reason']) ? ' (' . (string) $r['reason'] . ')' : '')
                        . (isset($r['calls']) ? sprintf(', %d segments, %d calls', (int) ($r['segments'] ?? 0), (int) $r['calls']) : '');
                }

                return ['ok' => true, 'message' => implode('; ', $parts), 'data' => $result];
            case 'approve':
                return self::result($p->review->approve($int('row_id'), $user, [
                    'with_warnings' => !empty($in['with_warnings']),
                    'reason' => $str('reason'),
                ]), 'Approved.');
            case 'reject':
                return self::result($p->review->reject($int('row_id'), $user, $str('reason')), 'Rejected.');
            case 'withdraw':
                return self::result($p->review->withdraw($int('row_id'), $user), 'Withdrawn. The URL now answers 410.');
            case 'rollback':
                return self::result($p->review->rollback($int('post_id'), $str('lang'), $user), 'Rolled back.');
            case 'manual':
                $texts = [];
                foreach ((isset($in['segments']) && is_array($in['segments']) ? $in['segments'] : []) as $id => $text) {
                    if (is_scalar($text)) {
                        $texts[(string) $id] = (string) $text;
                    }
                }
                $rowId = $int('row_id') > 0 ? $int('row_id') : null;
                $r = $p->review->saveManual($int('post_id'), $str('lang'), $texts, $user, $rowId);

                return is_wp_error($r)
                    ? ['ok' => false, 'message' => $r->get_error_message()]
                    : ['ok' => true, 'message' => 'Saved as a candidate. Guards ran as warnings.', 'data' => $r];
            default:
                return ['ok' => false, 'message' => 'Unknown action.'];
        }
    }

    /**
     * @return array{ok:bool, message:string}
     */
    private static function result(bool|\WP_Error $r, string $ok): array
    {
        if ($r instanceof \WP_Error) {
            return ['ok' => false, 'message' => $r->get_error_message()];
        }

        return ['ok' => true, 'message' => $ok];
    }
}
