<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Job;

use IndianOpinion\TranslateSidecar\Settings;
use IndianOpinion\TranslateSidecar\Storage\Store;
use IndianOpinion\TranslateSidecar\Support\Clock;

/**
 * Decides whether a call may be made: kill switch, credit pause, daily
 * cap, then the spend meter when one is present. Any doubt means no call.
 */
final class Gate
{
    public const SPEND_ALLOW_REASONS = ['ok', 'allowed', 'within_budget'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Store $store,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Reason calls are stopped before the cap is consulted, or null.
     */
    public function blocked(): ?string
    {
        if (!$this->settings->isEnabled()) {
            return 'disabled';
        }
        if ($this->settings->creditPausedUntil() > $this->clock->now()) {
            return 'credit_paused';
        }

        return null;
    }

    /**
     * Calls left today under the call cap.
     */
    public function remaining(): int
    {
        $used = $this->store->counterGet($this->clock->localDay(), 'calls');

        return max(0, $this->settings->dailyCap() - $used);
    }

    public function charsRemaining(): ?int
    {
        $cap = $this->settings->int('daily_chars');
        if ($cap <= 0) {
            return null;
        }

        return max(0, $cap - $this->store->counterGet($this->clock->localDay(), 'chars'));
    }

    /**
     * Counts one call (and its characters) before it is made. Returns
     * false, counting nothing, when the call would pass a cap.
     */
    public function reserveCall(int $chars): bool
    {
        $day = $this->clock->localDay();
        $charCap = $this->settings->int('daily_chars');
        if ($charCap > 0) {
            if (!$this->store->counterTryAdd($day, 'chars', $chars, $charCap)) {
                return false;
            }
        }
        if (!$this->store->counterTryAdd($day, 'calls', 1, $this->settings->dailyCap())) {
            if ($charCap > 0) {
                $this->store->counterAdd($day, 'chars', -$chars);
            }

            return false;
        }
        if ($charCap <= 0) {
            $this->store->counterAdd($day, 'chars', $chars);
        }

        return true;
    }

    /**
     * Asks the spend meter, when present, for a reservation.
     *
     * @param array<string, mixed> $context
     * @return array{allowed:bool, reason:string, reservation:mixed, present:bool}
     */
    public function spendPre(array $context): array
    {
        if (!has_filter('spend_meter_pre_spend')) {
            return ['allowed' => true, 'reason' => 'absent', 'reservation' => null, 'present' => false];
        }
        $decision = apply_filters('spend_meter_pre_spend', null, array_merge([
            'purpose' => 'translate_sidecar.translate',
            'caller' => 'translate-sidecar',
        ], $context));
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            return [
                'allowed' => false,
                'reason' => is_array($decision) ? (string) ($decision['reason'] ?? 'denied') : 'no_answer',
                'reservation' => is_array($decision) ? ($decision['reservation'] ?? null) : null,
                'present' => true,
            ];
        }
        $reason = (string) ($decision['reason'] ?? 'ok');
        $allowReasons = (array) apply_filters('translate_sidecar_spend_allow_reasons', self::SPEND_ALLOW_REASONS);
        if (!in_array($reason, $allowReasons, true)) {
            return ['allowed' => false, 'reason' => $reason, 'reservation' => $decision['reservation'] ?? null, 'present' => true];
        }

        return ['allowed' => true, 'reason' => $reason, 'reservation' => $decision['reservation'] ?? null, 'present' => true];
    }

    /**
     * @param array<string, mixed> $usage
     */
    public function spendRecord(mixed $reservation, array $usage): void
    {
        if ($reservation !== null) {
            do_action('spend_meter_record', $reservation, $usage);
        }
    }

    public function spendRelease(mixed $reservation): void
    {
        if ($reservation !== null) {
            do_action('spend_meter_release', $reservation);
        }
    }
}
