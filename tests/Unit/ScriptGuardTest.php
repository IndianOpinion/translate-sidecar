<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Guard\Guards;
use IndianOpinion\TranslateSidecar\Guard\Thresholds;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Script\ScriptTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance test 45 (cases 49, 50) and ruling 4: the script table is code
 * point ranges only, checked by a table-driven test.
 */
final class ScriptGuardTest extends TestCase
{
    /**
     * One sample letter per script in the built-in table.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function samples(): array
    {
        return [
            'Latin' => ['Latin', 'aéŁ'],
            'Greek' => ['Greek', 'αβγ'],
            'Cyrillic' => ['Cyrillic', 'жщы'],
            'Armenian' => ['Armenian', 'աբգ'],
            'Hebrew' => ['Hebrew', 'אבג'],
            'Arabic' => ['Arabic', 'ابت'],
            'Arabic presentation' => ['Arabic', "\u{FB50}\u{FE8D}"],
            'Syriac' => ['Syriac', 'ܐܒܓ'],
            'Thaana' => ['Thaana', 'ހށނ'],
            'Devanagari' => ['Devanagari', 'कखग'],
            'Bengali' => ['Bengali', 'কখগ'],
            'Gurmukhi' => ['Gurmukhi', 'ਕਖਗ'],
            'Gujarati' => ['Gujarati', 'કખગ'],
            'Oriya' => ['Oriya', 'କଖଗ'],
            'Tamil' => ['Tamil', 'கஙச'],
            'Telugu' => ['Telugu', 'కఖగ'],
            'Kannada' => ['Kannada', 'ಕಖಗ'],
            'Malayalam' => ['Malayalam', 'കഖഗ'],
            'Sinhala' => ['Sinhala', 'කඛග'],
            'Thai' => ['Thai', 'กขค'],
            'Lao' => ['Lao', 'ກຂຄ'],
            'Tibetan' => ['Tibetan', 'ཀཁག'],
            'Myanmar' => ['Myanmar', 'ကခဂ'],
            'Georgian' => ['Georgian', 'აბგ'],
            'Hangul syllables' => ['Hangul', '한국어'],
            'Hangul jamo' => ['Hangul', "\u{1100}\u{3131}"],
            'Ethiopic' => ['Ethiopic', 'ሀለሐ'],
            'Khmer' => ['Khmer', 'កខគ'],
            'Mongolian' => ['Mongolian', 'ᠠᠡᠢ'],
            'Han' => ['Han', '中文字'],
            'Han extension A' => ['Han', "\u{3400}\u{4DB5}"],
            'Hiragana' => ['Hiragana', 'ひらがな'],
            'Katakana' => ['Katakana', 'カタカナ'],
        ];
    }

    #[DataProvider('samples')]
    public function testEveryScriptInTheTableIsRecognised(string $script, string $letters): void
    {
        $counts = ScriptCounter::withDefaults()->counts($letters);
        self::assertSame([$script => mb_strlen($letters)], $counts);
    }

    public function testEveryTableEntryHasASample(): void
    {
        $sampled = array_unique(array_column(self::samples(), 0));
        sort($sampled);
        $table = array_keys(ScriptTable::defaults());
        sort($table);
        self::assertSame($table, $sampled);
    }

    public function testDigitsPunctuationEmojiAndJoinersAreNotCounted(): void
    {
        self::assertSame([], ScriptCounter::withDefaults()->counts("123 ,.!? \u{1F600} \u{200D}\u{200C} \u{0966}\u{0967} \u{0964}"));
    }

    /**
     * @return array<string, array{0:string, 1:list<string>, 2:string, 3:bool}>
     */
    public static function fixtures(): array
    {
        $hindi = 'नगर परिषद ने सोमवार शाम लंबी बहस के बाद नए बजट को मंज़ूरी दी और सदस्यों ने कहा कि यह योजना स्कूलों को धन देगी';
        $bengali = 'নগর পরিষদ সোমবার সন্ধ্যায় নতুন বাজেট অনুমোদন করেছে';

        return [
            'correct non-Latin translation passes' => [$hindi, ['Devanagari'], 'body', true],
            'body left in Latin fails' => ['The council approved the new budget on Monday evening after a long debate.', ['Devanagari'], 'body', false],
            'neighbouring script above 5 percent fails' => [$hindi . ' ' . $bengali, ['Devanagari'], 'body', false],
            'one stray glyph in a long text passes' => [$hindi . ' ক', ['Devanagari'], 'body', true],
            'short title with a third-script letter fails' => ['बजट ক', ['Devanagari'], 'title', false],
            'short title without a third script passes' => ['बजट', ['Devanagari'], 'title', true],
            'Latin target answered in Cyrillic fails' => ['Городской совет утвердил новый бюджет в понедельник вечером', ['Latin'], 'body', false],
            'Latin target in Latin passes' => ['Le conseil municipal a approuvé le nouveau budget lundi soir.', ['Latin'], 'body', true],
            'Latin names in a non-Latin body pass' => [$hindi . ' BBC UNESCO', ['Devanagari'], 'body', true],
            'multi-script language adds its shares' => ['市議会は月曜日の夕方に新しい予算を承認しました。カタカナもあります。', ['Han', 'Hiragana', 'Katakana'], 'body', true],
            'multi-script language with one script only fails' => ['市議会は月曜日の夕方に新しい予算を承認しました', ['Katakana'], 'body', false],
        ];
    }

    /**
     * @param list<string> $scripts
     */
    #[DataProvider('fixtures')]
    public function testScriptGuardFixtures(string $text, array $scripts, string $field, bool $passes): void
    {
        $guards = new Guards(ScriptCounter::withDefaults());
        $lang = Language::fromArray(['prefix' => 'xx', 'hreflang' => 'xx', 'scripts' => $scripts]);
        $report = new GuardReport();
        $guards->checkScript($text, $field, $lang, new Thresholds(), $report);
        self::assertSame($passes, $report->passed(), json_encode($report->toArray(), JSON_UNESCAPED_UNICODE) ?: '');
        if (!$passes) {
            self::assertSame('wrong_script', $report->firstReason());
            self::assertArrayHasKey('shares', $report->failures()[0]['detail']);
        }
    }

    public function testCombiningMarksCountWithTheirScript(): void
    {
        // हिन्दी: two of six code points are vowel signs and one is a virama.
        $counts = ScriptCounter::withDefaults()->counts('हिन्दी');
        self::assertSame(['Devanagari' => 6], $counts);
        // A shared combining accent with no precomposed form follows the letter before it.
        self::assertSame(['Latin' => 2], ScriptCounter::withDefaults()->counts("q\u{0301}"));
        $decomposed = "\u{0915}\u{093F}";
        self::assertSame(1.0, ScriptCounter::withDefaults()->shares($decomposed)['Devanagari']);
    }

    public function testRangesAreFilterable(): void
    {
        $counter = new ScriptCounter(['Custom' => [[0x41, 0x5A]]]);
        self::assertSame(['Custom' => 2, 'Other' => 2], $counter->counts('ABcd'));
    }
}
