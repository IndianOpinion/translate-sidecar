<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Script;

/**
 * Counts letters by script using code point ranges only.
 *
 * Only letters (general category L) and combining marks (category M) are
 * counted. Digits, punctuation, spaces, symbols, emoji and joiners are left
 * out. A combining mark inside a script's range counts for that script, so
 * scripts that write vowels as marks are not undercounted. A mark outside
 * every range (for example the shared diacritics block) takes the script of
 * the letter before it. A letter outside every range counts as "Other".
 */
final class ScriptCounter
{
    public const OTHER = 'Other';

    /** @var list<array{0:int,1:int,2:string}> */
    private array $ranges = [];

    /**
     * @param array<string, list<array{0:int,1:int}>> $table
     */
    public function __construct(array $table)
    {
        foreach ($table as $script => $ranges) {
            foreach ($ranges as $range) {
                $lo = (int) $range[0];
                $hi = (int) $range[1];
                if ($hi < $lo) {
                    throw new \InvalidArgumentException(sprintf('Bad range for script %s.', $script));
                }
                $this->ranges[] = [$lo, $hi, (string) $script];
            }
        }
        usort($this->ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    }

    public static function withDefaults(): self
    {
        return new self(ScriptTable::defaults());
    }

    /**
     * Script of one code point, or null when no range holds it.
     */
    public function scriptOf(int $cp): ?string
    {
        $lo = 0;
        $hi = count($this->ranges) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            [$a, $b, $script] = $this->ranges[$mid];
            if ($cp < $a) {
                $hi = $mid - 1;
            } elseif ($cp > $b) {
                $lo = $mid + 1;
            } else {
                return $script;
            }
        }

        return null;
    }

    /**
     * Letter counts per script.
     *
     * @return array<string, int>
     */
    public function counts(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (class_exists(\Normalizer::class)) {
            $normal = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normal)) {
                $text = $normal;
            }
        }
        $matched = preg_match_all('/(\p{L})|(\p{M})/u', $text, $m, PREG_SET_ORDER);
        if ($matched === false) {
            throw new \RuntimeException('Script count failed: invalid UTF-8 or regular expression error.');
        }
        $counts = [];
        $previous = null;
        foreach ($m as $match) {
            $isMark = !isset($match[1]) || $match[1] === '';
            $char = $isMark ? $match[2] : $match[1];
            $cp = mb_ord($char, 'UTF-8');
            if ($cp === false) {
                continue;
            }
            $script = $this->scriptOf($cp);
            if ($script === null) {
                if ($isMark) {
                    if ($previous === null) {
                        continue;
                    }
                    $script = $previous;
                } else {
                    $script = self::OTHER;
                }
            }
            $counts[$script] = ($counts[$script] ?? 0) + 1;
            $previous = $script;
        }

        return $counts;
    }

    /**
     * Share of counted letters per script, from 0 to 1.
     *
     * @return array<string, float>
     */
    public function shares(string $text): array
    {
        $counts = $this->counts($text);
        $total = array_sum($counts);
        if ($total === 0) {
            return [];
        }
        $shares = [];
        foreach ($counts as $script => $n) {
            $shares[$script] = (float) $n / $total;
        }
        arsort($shares);

        return $shares;
    }

    /**
     * The script with the most letters, or null for text without letters.
     */
    public function dominant(string $text): ?string
    {
        $shares = $this->shares($text);
        if ($shares === []) {
            return null;
        }

        return (string) array_key_first($shares);
    }
}
