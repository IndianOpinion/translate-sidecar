<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Support;

/**
 * One line per job outcome when WP_DEBUG_LOG is on. Never post text, never
 * keys.
 */
final class Log
{
    /**
     * @param array<string, scalar|null> $fields
     */
    public static function job(array $fields): void
    {
        if (!defined('WP_DEBUG_LOG') || !constant('WP_DEBUG_LOG')) {
            return;
        }
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = $k . '=' . preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $v);
        }
        error_log('translate-sidecar ' . implode(' ', $parts));
    }

    public static function error(string $where, \Throwable $e): void
    {
        if (!defined('WP_DEBUG_LOG') || !constant('WP_DEBUG_LOG')) {
            return;
        }
        error_log(sprintf('translate-sidecar error in %s: %s', $where, $e->getMessage()));
    }
}
