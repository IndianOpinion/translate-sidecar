<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Support;

/**
 * A deterministic pseudo translation: maps ASCII letters into the target
 * script and leaves placeholder tokens, digits and punctuation alone.
 */
final class Pseudo
{
    public static function translate(string $text, string $target): string
    {
        $primary = strtolower(explode('-', $target)[0]);
        $parts = preg_split('/(\{\/?\d+\/?\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{\/?\d+\/?\}$/', $part) === 1) {
                $out .= $part;
                continue;
            }
            $out .= (string) preg_replace_callback('/[A-Za-z]/', static function (array $m) use ($primary): string {
                $i = ord(strtolower($m[0])) - 97;

                return match ($primary) {
                    'hi', 'mr', 'ne' => mb_chr(0x0915 + $i, 'UTF-8'),
                    'ru', 'uk' => mb_chr(0x0430 + $i, 'UTF-8'),
                    'el' => mb_chr(0x03B1 + ($i % 24), 'UTF-8'),
                    default => chr(97 + (($i + 13) % 26)),
                };
            }, $part);
        }

        return $out;
    }
}
