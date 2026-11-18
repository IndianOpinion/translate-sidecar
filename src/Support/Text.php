<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Support;

use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Script\ScriptTable;

/**
 * Multi-byte text helpers. Pure functions, no WordPress calls.
 */
final class Text
{
    /**
     * Normal form used for hashing and comparison: NFC, whitespace
     * collapsed, trimmed.
     */
    public static function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($n)) {
                $text = $n;
            }
        }
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    public static function hash(string $text): string
    {
        return hash('sha256', self::normalise($text));
    }

    /**
     * Plain text from HTML: drops elements whose content is never text for
     * readers (script, style, pre, code and the like), then tags, then
     * decodes entities.
     */
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|pre|code|textarea|svg|math|template|noscript)\b[^>]*>.*?</\1\s*>#si', ' ', $html);
        $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
        $html = (string) preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Splits a string into grapheme clusters.
     *
     * @return list<string>
     */
    public static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (preg_match_all('/\X/u', $text, $m) === false) {
            throw new \RuntimeException('Invalid UTF-8.');
        }

        return $m[0];
    }

    /**
     * Trims to at most $max grapheme clusters, never through a character.
     * Where the text has spaces, cuts at the last space before the limit.
     * Appends nothing.
     */
    public static function trimGraphemes(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $g = self::graphemes($text);
        if (count($g) <= $max) {
            return $text;
        }
        $cut = array_slice($g, 0, $max);
        $lastSpace = null;
        foreach ($cut as $i => $cluster) {
            if ($cluster === ' ') {
                $lastSpace = $i;
            }
        }
        if ($lastSpace !== null && $lastSpace > (int) ($max / 2)) {
            $cut = array_slice($cut, 0, $lastSpace);
        }

        return rtrim(implode('', $cut), " ,;:.-");
    }

    /**
     * Word count by whitespace, or letters divided by 4 when the text is
     * mostly in a script written without spaces.
     */
    public static function wordCount(string $plain, ?ScriptCounter $counter = null): int
    {
        $plain = trim($plain);
        if ($plain === '') {
            return 0;
        }
        $counter ??= ScriptCounter::withDefaults();
        $dominant = $counter->dominant($plain);
        if ($dominant !== null && in_array($dominant, ScriptTable::spacelessScripts(), true)) {
            $letters = preg_match_all('/[\p{L}\p{M}\p{N}]/u', $plain);

            return intdiv((int) $letters, 4);
        }
        $tokens = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) ? count($tokens) : 0;
    }

    public static function letterCount(string $text): int
    {
        $n = preg_match_all('/\p{L}/u', $text);

        return $n === false ? 0 : $n;
    }

    /**
     * Maps every Unicode decimal digit to ASCII.
     */
    public static function asciiDigits(string $text): string
    {
        $zeros = [
            0x0660, 0x06F0, 0x07C0, 0x0966, 0x09E6, 0x0A66, 0x0AE6, 0x0B66, 0x0BE6, 0x0C66, 0x0CE6,
            0x0D66, 0x0DE6, 0x0E50, 0x0ED0, 0x0F20, 0x1040, 0x1090, 0x17E0, 0x1810, 0x1946, 0x19D0,
            0x1A80, 0x1A90, 0x1B50, 0x1BB0, 0x1C40, 0x1C50, 0xA620, 0xA8D0, 0xA900, 0xA9D0, 0xA9F0,
            0xAA50, 0xABF0, 0xFF10,
        ];
        $map = [];
        foreach ($zeros as $zero) {
            for ($d = 0; $d <= 9; $d++) {
                $map[mb_chr($zero + $d, 'UTF-8')] = (string) $d;
            }
        }

        return strtr($text, $map);
    }

    /**
     * Runs of two or more ASCII digits.
     *
     * @return list<string>
     */
    public static function digitRuns(string $text): array
    {
        preg_match_all('/[0-9]{2,}/', self::asciiDigits($text), $m);

        return $m[0];
    }
}
