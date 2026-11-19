<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Unit;

use IndianOpinion\TranslateSidecar\Guard\GuardReport;
use IndianOpinion\TranslateSidecar\Guard\Guards;
use IndianOpinion\TranslateSidecar\Guard\Thresholds;
use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Segment\Reassembler;
use IndianOpinion\TranslateSidecar\Segment\Segment;
use IndianOpinion\TranslateSidecar\Segment\Segmenter;
use IndianOpinion\TranslateSidecar\Segment\SourceFields;
use IndianOpinion\TranslateSidecar\Translator\TranslationRequest;
use IndianOpinion\TranslateSidecar\Translator\TranslationResponse;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance tests 37 to 44 (cases 40 to 48).
 */
final class GuardsTest extends TestCase
{
    private Guards $guards;
    private Language $fr;

    protected function setUp(): void
    {
        $this->guards = new Guards(ScriptCounter::withDefaults(), ['Indian Opinion']);
        $this->fr = Language::fromArray(['prefix' => 'fr', 'hreflang' => 'fr', 'scripts' => ['Latin']]);
    }

    /**
     * @param array<string, string> $sent
     * @param array<string, string> $got
     */
    private function response(array $sent, array $got, string $finish = 'stop', ?string $lang = 'fr', array $dupes = []): GuardReport
    {
        $req = new TranslationRequest('en', 'fr', $sent);
        $res = new TranslationResponse($got, $lang, $finish, 'p', 'm', [], $dupes);
        $segments = [];
        foreach ($sent as $id => $t) {
            $segments[$id] = new Segment($id, 'body', $t, hash('sha256', $t));
        }
        $report = new GuardReport();
        $this->guards->checkResponse($req, $res, $segments, $this->fr, new Thresholds(), $report);

        return $report;
    }

    /** Test 37 (case 40). */
    public function testMissingExtraAndEmptySegmentsFail(): void
    {
        self::assertSame(['missing_segments'], $this->response(['a' => 'One', 'b' => 'Two'], ['a' => 'Un'])->reasons());
        self::assertSame(['extra_segments'], $this->response(['a' => 'One'], ['a' => 'Un', 'z' => 'Zut'])->reasons());
        self::assertSame(['extra_segments'], $this->response(['a' => 'One'], ['a' => 'Un'], 'stop', 'fr', ['a'])->reasons());
        self::assertSame(['empty_segment'], $this->response(['a' => 'One'], ['a' => '  '])->reasons());
        self::assertTrue($this->response(['a' => 'One'], ['a' => 'Un'])->passed());
    }

    /** Test 38 (case 41). */
    public function testTruncation(): void
    {
        self::assertSame(['truncated'], $this->response(['a' => 'One'], ['a' => 'Un'], 'length')->reasons());
        $long = str_repeat('A long sentence that keeps going. ', 8);
        self::assertSame(['truncated'], $this->response(['a' => $long], ['a' => 'Une phrase.'])->reasons());
        self::assertTrue($this->response(['a' => 'Short one'], ['a' => 'C'])->passed());
    }

    /** Test 39 (case 42). */
    public function testPlaceholders(): void
    {
        $src = 'Read {1}the report{/1} at {2/}.';
        self::assertNull(Guards::placeholderProblem($src, 'Lisez {1}le rapport{/1} sur {2/}.'));
        self::assertNull(Guards::placeholderProblem($src, 'Sur {2/}, lisez {1}le rapport{/1}.'));
        self::assertSame('dropped {2/}', Guards::placeholderProblem($src, 'Lisez {1}le rapport{/1}.'));
        self::assertSame('duplicated {2/}', Guards::placeholderProblem($src, 'Lisez {1}le{/1} {2/} {2/}.'));
        self::assertSame('invented {3/}', Guards::placeholderProblem($src, 'Lisez {1}le{/1} {2/} {3/}.'));
        self::assertSame('not nested {/1}', Guards::placeholderProblem('{1}a {2}b{/2}{/1}', '{1}a {2}b{/1}{/2}'));
        self::assertSame(['placeholder_mismatch'], $this->response(['a' => $src], ['a' => 'Lisez le rapport.'])->reasons());
    }

    /** Test 40 (case 43). */
    public function testDroppedParagraphFailsStructure(): void
    {
        $src = '<p>One sentence here.</p><p>Two sentence here.</p>';
        $report = $this->guards->field($src, '<p>Une phrase ici. Deux phrases ici.</p>', $this->fr, 'body', new Thresholds());
        self::assertContains('structure_mismatch', $report->reasons());
    }

    /** Test 41 (case 44). */
    public function testLengthRatio(): void
    {
        $t = new Thresholds();
        $report = new GuardReport();
        $this->guards->checkRatio('abcdefghij', 'abc', $this->fr, $t, $report);
        self::assertSame(['length_ratio'], $report->reasons());
        $report = new GuardReport();
        $this->guards->checkRatio('abc', str_repeat('a', 10), $this->fr, $t, $report);
        self::assertSame(['length_ratio'], $report->reasons());
        $wide = Language::fromArray(['prefix' => 'fr', 'hreflang' => 'fr', 'scripts' => ['Latin'], 'ratio_min' => 0.1, 'ratio_max' => 5]);
        $report = new GuardReport();
        $this->guards->checkRatio('abcdefghij', 'abc', $wide, $t, $report);
        self::assertTrue($report->passed());
    }

    private function assembleCheck(SourceFields $src, array $translations): GuardReport
    {
        $seg = (new Segmenter())->segment($src);
        $out = (new Reassembler())->assemble($seg, $translations);
        $report = new GuardReport();
        $this->guards->checkAssembled($seg, $out, $src->content, $this->fr, new Thresholds(), $report);

        return $report;
    }

    /** Test 42 (case 45). */
    public function testUnchangedOutputFailsUntranslated(): void
    {
        $src = new SourceFields(
            'The council approves the budget',
            '<p>The council approved the new budget on Monday.</p><p>Members said it funds the schools well.</p><p>Indian Opinion</p><p>Go now</p>'
        );
        $same = $this->assembleCheck($src, [
            'title' => 'Le conseil approuve le budget',
            's1' => 'The council approved the new budget on Monday.',
            's2' => 'Members said it funds the schools well.',
            's3' => 'Indian Opinion',
            's4' => 'Go now',
        ]);
        self::assertContains('untranslated', $same->reasons());

        $ok = $this->assembleCheck($src, [
            'title' => 'Le conseil approuve le budget',
            's1' => 'Le conseil a approuvé le nouveau budget lundi.',
            's2' => 'Les membres disent qu il finance bien les écoles.',
            's3' => 'Indian Opinion',
            's4' => 'Go now',
        ]);
        self::assertNotContains('untranslated', $ok->reasons(), 'Name-only and short segments are exempt.');

        $title = $this->assembleCheck($src, [
            'title' => 'The council approves the budget',
            's1' => 'Le conseil a approuvé le nouveau budget lundi.',
            's2' => 'Les membres disent qu il finance bien les écoles.',
            's3' => 'Indian Opinion',
            's4' => 'Go now',
        ]);
        self::assertContains('untranslated', $title->reasons());
    }

    /** Test 43 (case 46). */
    public function testNumbersWarnByDefaultFailWhenSetAndMapNativeDigits(): void
    {
        $report = new GuardReport();
        $this->guards->checkNumbers('In 2026 the fund grew by 45 percent', 'En 2026 le fonds a augmenté', new Thresholds(), $report);
        self::assertTrue($report->passed());
        self::assertSame('numbers_missing', $report->warnings()[0]['reason']);
        self::assertSame(['45'], $report->warnings()[0]['detail']['missing']);

        $report = new GuardReport();
        $this->guards->checkNumbers('In 2026 the fund grew by 45 percent', 'En 2026 le fonds', new Thresholds(numbersFail: true), $report);
        self::assertSame(['numbers_missing'], $report->reasons());

        $report = new GuardReport();
        $this->guards->checkNumbers('In 2026 it grew by 45', "\u{0968}\u{0966}\u{0968}\u{096C} में \u{096A}\u{096B}", new Thresholds(numbersFail: true), $report);
        self::assertTrue($report->passed());
        self::assertFalse($report->hasWarnings());
    }

    /** Test 44 (cases 47, 48). */
    public function testDeclaredAndDetectedWrongLanguage(): void
    {
        self::assertSame(['wrong_language'], $this->response(['a' => 'One'], ['a' => 'Uno'], 'stop', 'es')->reasons());
        self::assertTrue($this->response(['a' => 'One'], ['a' => 'Un'], 'stop', 'fr-FR')->passed());
        self::assertTrue($this->response(['a' => 'One'], ['a' => 'Un'], 'stop', null)->passed());

        $detecting = new Guards(ScriptCounter::withDefaults(), [], static fn (string $text): ?string => 'es');
        $report = $detecting->field('Hello there my friend', 'Hola amigo mio querido', $this->fr, 'title', new Thresholds());
        self::assertContains('wrong_language', $report->reasons());
    }

    /** Test 47 (case 52). */
    public function testGuardThatThrowsFailsClosed(): void
    {
        $throwing = new Guards(ScriptCounter::withDefaults(), [], static function (string $t): ?string {
            throw new \RuntimeException('detector broke');
        });
        $report = $throwing->field('Hello there my friend', 'Bonjour mon ami cher', $this->fr, 'title', new Thresholds());
        self::assertSame(['guard_error'], $report->reasons());
    }
}
