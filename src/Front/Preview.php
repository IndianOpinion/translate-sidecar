<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Front;

/**
 * Preview tokens: per user, per row, valid for one hour.
 */
final class Preview
{
    public const TTL = 3600;
    public const PARAM_ROW = 'ts_preview';
    public const PARAM_EXPIRES = 'ts_expires';
    public const PARAM_TOKEN = 'ts_token';

    public static function token(int $userId, int $rowId, int $expires): string
    {
        $key = function_exists('wp_salt') ? wp_salt('nonce') : 'translate-sidecar';

        return hash_hmac('sha256', 'translate_sidecar_preview|' . $userId . '|' . $rowId . '|' . $expires, $key);
    }

    /**
     * Query arguments for a preview link.
     *
     * @return array<string, string>
     */
    public static function args(int $userId, int $rowId, int $now): array
    {
        $expires = $now + self::TTL;

        return [
            self::PARAM_ROW => (string) $rowId,
            self::PARAM_EXPIRES => (string) $expires,
            self::PARAM_TOKEN => self::token($userId, $rowId, $expires),
        ];
    }

    /**
     * The row id when the token is valid for this user and not expired.
     *
     * @param array<string, mixed> $query
     */
    public static function validate(array $query, int $userId, int $now): ?int
    {
        $row = isset($query[self::PARAM_ROW]) && is_scalar($query[self::PARAM_ROW]) ? (int) $query[self::PARAM_ROW] : 0;
        $expires = isset($query[self::PARAM_EXPIRES]) && is_scalar($query[self::PARAM_EXPIRES]) ? (int) $query[self::PARAM_EXPIRES] : 0;
        $token = isset($query[self::PARAM_TOKEN]) && is_string($query[self::PARAM_TOKEN]) ? $query[self::PARAM_TOKEN] : '';
        if ($row <= 0 || $userId <= 0 || $expires < $now || $expires > $now + self::TTL || $token === '') {
            return null;
        }

        return hash_equals(self::token($userId, $row, $expires), $token) ? $row : null;
    }
}
