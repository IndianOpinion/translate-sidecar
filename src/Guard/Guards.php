<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Guard;

use IndianOpinion\TranslateSidecar\Language\Language;
use IndianOpinion\TranslateSidecar\Script\ScriptCounter;
use IndianOpinion\TranslateSidecar\Segment\Assembled;
use IndianOpinion\TranslateSidecar\Segment\Segment;
use IndianOpinion\TranslateSidecar\Segment\SegmentedPost;
use IndianOpinion\TranslateSidecar\Support\Text;
use IndianOpinion\TranslateSidecar\Translator\TranslationRequest;
use IndianOpinion\TranslateSidecar\Translator\TranslationResponse;

/**
 * Every check a machine translation must pass. Pure: no I/O apart from the
 * optional language detector callback.
 */
final class Guards
{
    /** @var callable|null fn(string $text): ?string */
    private $detector;

    /**
     * @param list<string> $doNotTranslate terms that stay as they are
     */
    public function __construct(
        private readonly ScriptCounter $counter,
        private readonly array $doNotTranslate = [],
        ?callable $detector = null,
    ) {
        $this->detector = $detector;
    }

    /**
     * Checks one call's response against its request: truncation,
     * completeness, placeholders, declared language.
     *
     * @param array<string, Segment> $segments the segments sent, by id
     */
    public function checkResponse(TranslationRequest $request, TranslationResponse $response, array $segments, Language $lang, Thresholds $t, GuardReport $report): void
    {
        if ($response->finishReason === TranslationResponse::FINISH_LENGTH) {
            $report->fail('truncated', '', ['finish_reason' => 'length']);
        }
        $sent = array_keys($request->segments);
        $got = array_keys($response->segments);
        $missing = array_values(array_diff($sent, $got));
        $extra = array_values(array_diff($got, $sent));
        if ($missing !== []) {
            $report->fail('missing_segments', '', ['ids' => $missing]);
        }
        if ($extra !== [] || $response->duplicateIds !== []) {
            $report->fail('extra_segments', '', ['ids' => array_values(array_unique(array_merge($extra, $response->duplicateIds)))]);
        }
        foreach ($request->segments as $id => $source) {
            if (!array_key_exists($id, $response->segments)) {
                continue;
            }
            $translated = $response->segments[$id];
            $sourcePlain = trim((string) preg_replace('/\{\/?\d+\/?\}/', '', $source));
            if ($sourcePlain !== '' && trim((string) preg_replace('/\{\/?\d+\/?\}/', '', $translated)) === '') {
                $report->fail('empty_segment', '', ['id' => $id]);
                continue;
            }
            $srcLen = mb_strlen($source, 'UTF-8');
            if ($srcLen >= $t->truncationMinChars && mb_strlen($translated, 'UTF-8') < $srcLen * $t->truncationRatio) {
                $report->fail('truncated', '', ['id' => $id, 'source_chars' => $srcLen, 'translated_chars' => mb_strlen($translated, 'UTF-8')]);
            }
            $problem = self::placeholderProblem($source, $translated);
            if ($problem !== null) {
                $report->fail('placeholder_mismatch', '', ['id' => $id, 'problem' => $problem]);
            }
        }
        if ($response->declaredLang !== null && !Language::tagsMatch($response->declaredLang, $lang->hreflang)) {
            $report->fail('wrong_language', '', ['declared' => $response->declaredLang, 'expected' => $lang->hreflang]);
        }
    }

    /**
     * Checks the assembled translation: structure, length ratio, unchanged
     * output, numbers, detector and script.
     */
    public function checkAssembled(SegmentedPost $source, Assembled $out, string $sourceBodyHtml, Language $lang, Thresholds $t, GuardReport $report): void
    {
        $structure = self::structureDiff($sourceBodyHtml, $out->content);
        if ($structure !== []) {
            $report->fail('structure_mismatch', 'body', ['diff' => $structure]);
        }

        $srcBody = [];
        foreach ($source->byField('body') as $seg) {
            $srcBody[] = $seg->plainText();
        }
        $srcBodyText = implode("\n", $srcBody);
        $outBodyText = $out->bodyText();
        $this->checkRatio($srcBodyText, $outBodyText, $lang, $t, $report);
        $this->checkUnchanged($source, $out, $t, $report);
        $this->checkNumbers($source->fields['title'] . "\n" . $srcBodyText, $out->title . "\n" . $outBodyText, $t, $report);

        $fields = [
            'title' => $out->title,
            'body' => $outBodyText,
            'excerpt' => $source->hasManualExcerpt ? $out->excerpt : '',
            'seo_title' => $out->seoTitle,
            'meta_description' => $out->metaDescription,
        ];
        foreach ($fields as $field => $text) {
            if (trim($text) === '') {
                continue;
            }
            $this->checkScript($text, $field, $lang, $t, $report);
            if ($field === 'title' || $field === 'body') {
                $this->checkDetector($text, $field, $lang, $report);
            }
        }
    }

    /**
     * Pure guard for one field, as exposed by translate_sidecar_guard().
     */
    public function field(string $source, string $translated, Language $lang, string $field, Thresholds $t): GuardReport
    {
        $report = new GuardReport();
        try {
            if ($field === 'body') {
                $structure = self::structureDiff($source, $translated);
                if ($structure !== []) {
                    $report->fail('structure_mismatch', 'body', ['diff' => $structure]);
                }
                $srcText = Text::plain($source);
                $outText = Text::plain($translated);
            } else {
                $srcText = Text::plain($source);
                $outText = Text::plain($translated);
            }
            if ($field === 'body') {
                $this->checkRatio($srcText, $outText, $lang, $t, $report);
            }
            if ($srcText !== '' && Text::normalise(mb_strtolower($srcText)) === Text::normalise(mb_strtolower($outText)) && !$this->isExempt($srcText, $t, $field === 'title')) {
                $report->fail('untranslated', $field, ['unchanged' => true]);
            }
            $this->checkNumbers($srcText, $outText, $t, $report);
            $this->checkDetector($outText, $field, $lang, $report);
            $this->checkScript($outText, $field, $lang, $t, $report);
        } catch (\Throwable $e) {
            $report->fail('guard_error', $field, ['error' => $e->getMessage()]);
        }

        return $report;
    }

    public function checkRatio(string $source, string $translated, Language $lang, Thresholds $t, GuardReport $report): void
    {
        $srcLetters = Text::letterCount($source);
        if ($srcLetters === 0) {
            return;
        }
        $ratio = Text::letterCount($translated) / $srcLetters;
        $min = $lang->ratioMin ?? $t->ratioMin;
        $max = $lang->ratioMax ?? $t->ratioMax;
        $report->metric('length_ratio', round($ratio, 3));
        if ($ratio < $min || $ratio > $max) {
            $report->fail('length_ratio', 'body', ['ratio' => round($ratio, 3), 'min' => $min, 'max' => $max]);
        }
    }

    private function checkUnchanged(SegmentedPost $source, Assembled $out, Thresholds $t, GuardReport $report): void
    {
        $eligible = 0;
        $unchanged = [];
        foreach ($source->segments as $id => $seg) {
            if ($seg->field === 'alt') {
                continue;
            }
            $translated = $out->segmentTexts[$id] ?? '';
            $same = self::comparable($seg->text) === self::comparable($translated);
            if ($id === 'title') {
                if ($same && !$this->isExempt($seg->plainText(), $t, true)) {
                    $report->fail('untranslated', 'title', ['unchanged' => true]);
                }
                continue;
            }
            if ($this->isExempt($seg->plainText(), $t, false)) {
                continue;
            }
            $eligible++;
            if ($same) {
                $unchanged[] = $id;
            }
        }
        if ($eligible === 0) {
            return;
        }
        $share = count($unchanged) / $eligible;
        $report->metric('unchanged_share', round($share, 3));
        if ($share > $t->unchangedCeiling) {
            $report->fail('untranslated', 'body', ['share' => round($share, 3), 'ids' => $unchanged]);
        }
    }

    private static function comparable(string $text): string
    {
        $text = (string) preg_replace('/\{\/?\d+\/?\}/', ' ', $text);

        return Text::normalise(mb_strtolower($text, 'UTF-8'));
    }

    /**
     * Segments that may legitimately stay the same: short ones, numbers
     * and punctuation only, names on the do-not-translate list, and URLs.
     * The short rule does not apply to the title.
     */
    private function isExempt(string $plain, Thresholds $t, bool $isTitle): bool
    {
        $plain = trim($plain);
        if (preg_match('/\p{L}/u', $plain) !== 1) {
            return true;
        }
        if (preg_match('#^(https?://|www\.)\S+$#i', $plain) === 1) {
            return true;
        }
        $rest = $plain;
        foreach ($this->doNotTranslate as $term) {
            if ($term !== '') {
                $rest = str_ireplace($term, ' ', $rest);
            }
        }
        if (preg_match('/\p{L}/u', $rest) !== 1) {
            return true;
        }
        if (!$isTitle && Text::wordCount($plain, $this->counter) < $t->shortWords) {
            return true;
        }

        return false;
    }

    public function checkNumbers(string $source, string $translated, Thresholds $t, GuardReport $report): void
    {
        $have = array_flip(Text::digitRuns($translated));
        $missing = [];
        foreach (array_unique(Text::digitRuns($source)) as $run) {
            if (!isset($have[$run])) {
                $missing[] = $run;
            }
        }
        if ($missing === []) {
            return;
        }
        if ($t->numbersFail) {
            $report->fail('numbers_missing', 'body', ['missing' => $missing]);
        } else {
            $report->warn('numbers_missing', 'body', ['missing' => $missing]);
        }
    }

    private function checkDetector(string $text, string $field, Language $lang, GuardReport $report): void
    {
        if ($this->detector === null || trim($text) === '') {
            return;
        }
        $tag = ($this->detector)($text);
        if (is_string($tag) && $tag !== '' && !Language::tagsMatch($tag, $lang->hreflang)) {
            $report->fail('wrong_language', $field, ['detected' => $tag, 'expected' => $lang->hreflang]);
        }
    }

    /**
     * The script guard (case 49).
     */
    public function checkScript(string $text, string $field, Language $lang, Thresholds $t, GuardReport $report): void
    {
        if ($lang->noScriptGuard || $lang->scripts === []) {
            return;
        }
        $counts = $this->counter->counts($text);
        $total = array_sum($counts);
        $target = 0;
        $latin = $counts['Latin'] ?? 0;
        $third = 0;
        foreach ($counts as $script => $n) {
            if (in_array($script, $lang->scripts, true)) {
                $target += $n;
            } elseif ($script !== 'Latin') {
                $third += $n;
            }
        }
        $shares = [];
        foreach ($counts as $script => $n) {
            $shares[$script] = $total > 0 ? round($n / $total, 4) : 0.0;
        }
        $report->metric('script_' . $field, $shares);
        $detail = ['shares' => $shares, 'letters' => $total];
        if ($total < $t->smallField) {
            if ($third > 0) {
                $report->fail('wrong_script', $field, $detail + ['rule' => 'third_script_in_short_field']);
            }

            return;
        }
        $isTitle = $field === 'title' || $field === 'seo_title';
        $min = $isTitle ? $t->titleShare : $t->bodyShare;
        if ($target / $total < $min) {
            $report->fail('wrong_script', $field, $detail + ['rule' => 'target_share', 'min' => $min]);

            return;
        }
        if ($third / $total > $t->thirdScript) {
            $report->fail('wrong_script', $field, $detail + ['rule' => 'third_script', 'max' => $t->thirdScript]);

            return;
        }
        if (in_array('Latin', $lang->scripts, true) && $latin / $total < $t->latinShare) {
            $report->fail('wrong_script', $field, $detail + ['rule' => 'latin_share', 'min' => $t->latinShare]);
        }
    }

    /**
     * Describes how the placeholder tokens of a translation differ from
     * its source, or null when they match: each token exactly once, none
     * invented, pairs properly nested. Order may change.
     */
    public static function placeholderProblem(string $source, string $translated): ?string
    {
        preg_match_all('/\{\/?\d+\/?\}/', $source, $s);
        preg_match_all('/\{\/?\d+\/?\}/', $translated, $o);
        $src = array_count_values($s[0]);
        $out = array_count_values($o[0]);
        foreach ($src as $token => $n) {
            $got = $out[$token] ?? 0;
            if ($got === 0) {
                return 'dropped ' . $token;
            }
            if ($got > 1) {
                return 'duplicated ' . $token;
            }
        }
        foreach ($out as $token => $n) {
            if (!isset($src[$token])) {
                return 'invented ' . $token;
            }
        }
        $stack = [];
        foreach ($o[0] as $token) {
            if (preg_match('/^\{(\d+)\}$/', $token, $m) === 1) {
                $stack[] = $m[1];
            } elseif (preg_match('/^\{\/(\d+)\}$/', $token, $m) === 1) {
                $top = array_pop($stack);
                if ($top !== $m[1]) {
                    return 'not nested ' . $token;
                }
            }
        }
        if ($stack !== []) {
            return 'unclosed {' . $stack[0] . '}';
        }

        return null;
    }

    /**
     * Counts of structural elements that differ between two bodies.
     *
     * @return array<string, array{source:int, translated:int}>
     */
    public static function structureDiff(string $source, string $translated): array
    {
        $diff = [];
        $a = self::structure($source);
        $b = self::structure($translated);
        foreach ($a as $key => $n) {
            if ($n !== $b[$key]) {
                $diff[$key] = ['source' => $n, 'translated' => $b[$key]];
            }
        }

        return $diff;
    }

    /**
     * @return array<string, int>
     */
    public static function structure(string $html): array
    {
        $count = static fn (string $re): int => (int) preg_match_all($re, $html);

        return [
            'blocks' => $count('/<!--\s+wp:/'),
            'paragraphs' => $count('/<p[\s>]/i'),
            'headings' => $count('/<h[1-6][\s>]/i'),
            'list_items' => $count('/<li[\s>]/i'),
            'table_cells' => $count('/<t[dh][\s>]/i'),
            'figures' => $count('/<figure[\s>]/i'),
            'links' => $count('/<a\s/i'),
        ];
    }
}
