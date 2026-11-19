<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Script;

/**
 * Built-in table of Unicode code point ranges per script.
 *
 * Ranges follow the Unicode block layout. A letter or combining mark is
 * assigned to the script whose range contains it. Combining marks outside
 * every range (the shared diacritics block) take the script of the letter
 * before them; see ScriptCounter.
 */
final class ScriptTable
{
    /**
     * @return array<string, list<array{0:int,1:int}>>
     */
    public static function defaults(): array
    {
        return [
            'Latin' => [
                [0x0041, 0x005A], [0x0061, 0x007A], [0x00AA, 0x00AA], [0x00BA, 0x00BA],
                [0x00C0, 0x00D6], [0x00D8, 0x00F6], [0x00F8, 0x024F], [0x0250, 0x02AF],
                [0x1D00, 0x1D7F], [0x1E00, 0x1EFF], [0x2C60, 0x2C7F], [0xA720, 0xA7FF],
                [0xAB30, 0xAB6F], [0xFB00, 0xFB06], [0xFF21, 0xFF3A], [0xFF41, 0xFF5A],
            ],
            'Greek' => [[0x0370, 0x03FF], [0x1F00, 0x1FFF]],
            'Cyrillic' => [[0x0400, 0x04FF], [0x0500, 0x052F], [0x1C80, 0x1C8F], [0x2DE0, 0x2DFF], [0xA640, 0xA69F]],
            'Armenian' => [[0x0530, 0x058F], [0xFB13, 0xFB17]],
            'Hebrew' => [[0x0590, 0x05FF], [0xFB1D, 0xFB4F]],
            'Arabic' => [
                [0x0600, 0x06FF], [0x0750, 0x077F], [0x0870, 0x089F], [0x08A0, 0x08FF],
                [0xFB50, 0xFDFF], [0xFE70, 0xFEFF],
            ],
            'Syriac' => [[0x0700, 0x074F], [0x0860, 0x086F]],
            'Thaana' => [[0x0780, 0x07BF]],
            'Devanagari' => [[0x0900, 0x097F], [0xA8E0, 0xA8FF]],
            'Bengali' => [[0x0980, 0x09FF]],
            'Gurmukhi' => [[0x0A00, 0x0A7F]],
            'Gujarati' => [[0x0A80, 0x0AFF]],
            'Oriya' => [[0x0B00, 0x0B7F]],
            'Tamil' => [[0x0B80, 0x0BFF]],
            'Telugu' => [[0x0C00, 0x0C7F]],
            'Kannada' => [[0x0C80, 0x0CFF]],
            'Malayalam' => [[0x0D00, 0x0D7F]],
            'Sinhala' => [[0x0D80, 0x0DFF]],
            'Thai' => [[0x0E00, 0x0E7F]],
            'Lao' => [[0x0E80, 0x0EFF]],
            'Tibetan' => [[0x0F00, 0x0FFF]],
            'Myanmar' => [[0x1000, 0x109F], [0xA9E0, 0xA9FF], [0xAA60, 0xAA7F]],
            'Georgian' => [[0x10A0, 0x10FF], [0x1C90, 0x1CBF], [0x2D00, 0x2D2F]],
            'Hangul' => [
                [0x1100, 0x11FF], [0x3130, 0x318F], [0xA960, 0xA97F], [0xAC00, 0xD7AF],
                [0xD7B0, 0xD7FF], [0xFFA0, 0xFFDC],
            ],
            'Ethiopic' => [[0x1200, 0x137F], [0x1380, 0x139F], [0x2D80, 0x2DDF], [0xAB00, 0xAB2F]],
            'Khmer' => [[0x1780, 0x17FF], [0x19E0, 0x19FF]],
            'Mongolian' => [[0x1800, 0x18AF]],
            'Han' => [
                [0x2E80, 0x2FDF], [0x3005, 0x3007], [0x3021, 0x3029], [0x3038, 0x303B],
                [0x3400, 0x4DBF], [0x4E00, 0x9FFF], [0xF900, 0xFAFF], [0x20000, 0x2FA1F],
            ],
            'Hiragana' => [[0x3040, 0x309F]],
            'Katakana' => [[0x30A0, 0x30FF], [0x31F0, 0x31FF], [0xFF66, 0xFF9F]],
        ];
    }

    /**
     * Scripts usually written without spaces between words. Used by the
     * minimum length rule.
     *
     * @return list<string>
     */
    public static function spacelessScripts(): array
    {
        return ['Han', 'Hiragana', 'Katakana', 'Thai', 'Lao', 'Khmer', 'Myanmar', 'Tibetan'];
    }

    /**
     * Default scripts for common primary language subtags. The operator can
     * override the scripts of every registered language.
     *
     * @return array<string, list<string>>
     */
    public static function languageScripts(): array
    {
        $latin = [
            'af', 'az', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'en', 'eo', 'es', 'et', 'eu', 'fi', 'fil',
            'fr', 'ga', 'gl', 'ha', 'hr', 'hu', 'id', 'ig', 'is', 'it', 'jv', 'ku', 'la', 'lb', 'lt',
            'lv', 'mg', 'mi', 'ms', 'mt', 'nb', 'nl', 'nn', 'no', 'pl', 'pt', 'ro', 'sk', 'sl', 'so',
            'sq', 'sv', 'sw', 'tl', 'tr', 'uz', 'vi', 'xh', 'yo', 'zu',
        ];
        $map = [];
        foreach ($latin as $code) {
            $map[$code] = ['Latin'];
        }
        $others = [
            'el' => ['Greek'],
            'ru' => ['Cyrillic'], 'uk' => ['Cyrillic'], 'be' => ['Cyrillic'], 'bg' => ['Cyrillic'],
            'mk' => ['Cyrillic'], 'sr' => ['Cyrillic'], 'kk' => ['Cyrillic'], 'ky' => ['Cyrillic'],
            'tg' => ['Cyrillic'], 'mn' => ['Cyrillic'],
            'hy' => ['Armenian'],
            'he' => ['Hebrew'], 'yi' => ['Hebrew'],
            'ar' => ['Arabic'], 'fa' => ['Arabic'], 'ur' => ['Arabic'], 'ps' => ['Arabic'],
            'sd' => ['Arabic'], 'ug' => ['Arabic'],
            'syr' => ['Syriac'],
            'dv' => ['Thaana'],
            'hi' => ['Devanagari'], 'mr' => ['Devanagari'], 'ne' => ['Devanagari'],
            'sa' => ['Devanagari'], 'kok' => ['Devanagari'], 'mai' => ['Devanagari'],
            'bn' => ['Bengali'], 'as' => ['Bengali'],
            'pa' => ['Gurmukhi'],
            'gu' => ['Gujarati'],
            'or' => ['Oriya'],
            'ta' => ['Tamil'],
            'te' => ['Telugu'],
            'kn' => ['Kannada'],
            'ml' => ['Malayalam'],
            'si' => ['Sinhala'],
            'th' => ['Thai'],
            'lo' => ['Lao'],
            'bo' => ['Tibetan'], 'dz' => ['Tibetan'],
            'my' => ['Myanmar'],
            'ka' => ['Georgian'],
            'ko' => ['Hangul'],
            'am' => ['Ethiopic'], 'ti' => ['Ethiopic'],
            'km' => ['Khmer'],
            'zh' => ['Han'],
            'ja' => ['Han', 'Hiragana', 'Katakana'],
        ];

        return $map + $others;
    }

    /**
     * Scripts for a language tag or prefix, from the built-in map.
     *
     * @return list<string>
     */
    public static function scriptsFor(string $tag): array
    {
        $primary = strtolower((string) preg_replace('/[-_].*$/', '', $tag));
        $map = self::languageScripts();

        return $map[$primary] ?? [];
    }
}
