<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Support;

/**
 * Time source. Tests replace it; production uses the system clock.
 */
class Clock
{
    public function now(): int
    {
        return time();
    }

    /** MySQL datetime in UTC. */
    public function gmt(?int $ts = null): string
    {
        return gmdate('Y-m-d H:i:s', $ts ?? $this->now());
    }

    /** The site-local day (Y-m-d), whose boundary is local midnight. */
    public function localDay(?int $ts = null): string
    {
        return $this->localDate('Y-m-d', $ts ?? $this->now());
    }

    /** Unix time of the next local midnight. */
    public function nextLocalMidnight(?int $ts = null): int
    {
        $ts ??= $this->now();
        $tz = self::timezone();
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->setTime(0, 0)->modify('+1 day');

        return $d->getTimestamp();
    }

    public function localDate(string $format, int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(self::timezone())->format($format);
    }

    public static function timezone(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new \DateTimeZone('UTC');
    }

    /**
     * W3C datetime in UTC from a GMT column value. Never goes through a
     * time-zone-aware formatter.
     */
    public static function w3cFromGmt(string $gmt): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $gmt, new \DateTimeZone('UTC'));
        if ($d === false) {
            throw new \InvalidArgumentException(sprintf('Not a GMT datetime: "%s".', $gmt));
        }

        return $d->format('Y-m-d\TH:i:s\Z');
    }
}
