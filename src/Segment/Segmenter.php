<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

use IndianOpinion\TranslateSidecar\Support\Text;

/**
 * Cuts a post into translatable segments at block level.
 *
 * The body is tokenised into comments, tags and text. Runs of text and
 * inline tags between block boundaries become segments; inline tags turn
 * into numbered placeholder tokens. Markup, attributes, URLs, shortcodes,
 * code and anything marked "do not translate" never reach a segment, and
 * the original markup between segments is kept byte for byte.
 */
final class Segmenter
{
    public const ATOM = "\u{E000}";
    public const BOUNDARY = "\u{E002}";
    public const ALT = "\u{E003}";
    public const END = "\u{E001}";

    /** Inline elements that may sit inside a segment. */
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'data', 'dfn', 'em', 'i', 'img', 'mark',
        'q', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'wbr', 'del', 'ins',
        'font', 'label', 'code', 'kbd', 'samp', 'var',
    ];

    /** Elements whose content is copied verbatim. */
    private const VERBATIM = [
        'pre', 'code', 'kbd', 'samp', 'script', 'style', 'textarea', 'svg', 'math', 'iframe',
        'noscript', 'template', 'object', 'video', 'audio', 'canvas', 'select',
    ];

    private const VOID = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source',
        'track', 'wbr', 'param',
    ];

    /** @var array<int, string> */
    private array $sentinels = [];
    /** @var array<int, array{segment:?string, value:string}> */
    private array $alts = [];
    /** @var array<string, Segment> */
    private array $segments = [];
    private int $bodyCounter = 0;

    /**
     * @param list<string> $verbatimBlocks block names copied verbatim
     * @param list<string> $shortcodes registered shortcode tags
     * @param list<string> $captionShortcodes shortcodes whose inner text is translated
     */
    public function __construct(
        private readonly array $verbatimBlocks = [
            'core/code', 'core/preformatted', 'core/shortcode', 'core/embed', 'core/freeform-code',
        ],
        private readonly array $shortcodes = [],
        private readonly bool $translateAlt = true,
        private readonly array $captionShortcodes = ['caption', 'wp_caption'],
    ) {
    }

    public function segment(SourceFields $source): SegmentedPost
    {
        $this->sentinels = [];
        $this->alts = [];
        $this->segments = [];
        $this->bodyCounter = 0;

        $fields = [];
        foreach (['title' => $source->title, 'excerpt' => $source->excerpt, 'seo_title' => $source->seoTitle, 'meta_description' => $source->metaDescription] as $field => $value) {
            $plain = Text::plain($value);
            $fields[$field] = $plain;
            if ($plain !== '' && preg_match('/\p{L}/u', $plain) === 1) {
                $this->segments[$field] = new Segment($field, $field, $plain, Text::hash($plain));
            }
        }

        $content = $source->content;
        if (!self::hasBlocks($content) && function_exists('wpautop')) {
            $content = wpautop($content);
        }
        $parts = $this->segmentBody($content);
        $fields['body'] = Text::plain($content);

        // Title first, then body in document order, then the other fields.
        $ordered = [];
        if (isset($this->segments['title'])) {
            $ordered['title'] = $this->segments['title'];
        }
        foreach ($this->segments as $id => $seg) {
            if ($seg->field === 'body' || $seg->field === 'alt') {
                $ordered[$id] = $seg;
            }
        }
        foreach (['excerpt', 'seo_title', 'meta_description'] as $field) {
            if (isset($this->segments[$field])) {
                $ordered[$field] = $this->segments[$field];
            }
        }

        return new SegmentedPost(
            $ordered,
            $parts,
            $this->sentinels,
            $this->alts,
            $fields,
            $source->hash(),
            trim($source->excerpt) !== ''
        );
    }

    public static function hasBlocks(string $content): bool
    {
        return str_contains($content, '<!-- wp:');
    }

    /**
     * @return list<string|array{seg:string}>
     */
    private function segmentBody(string $html): array
    {
        $html = str_replace([self::ATOM, self::BOUNDARY, self::ALT, self::END], '', $html);
        $html = $this->protectShortcodes($html);
        $tokens = $this->tokenize($html);

        $parts = [];
        $run = [];
        $blockStack = [];
        $verbatimBlocks = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$type, $raw, $name] = $tokens[$i];
            if ($type === 'comment') {
                $this->flush($run, $parts);
                $parts[] = $raw;
                if (preg_match('#^<!--\s+wp:([a-z0-9/-]+)(\s+(\{.*\}))?\s*(/)?-->$#s', $raw, $m) === 1) {
                    if (($m[4] ?? '') !== '/') {
                        $verbatim = $this->isVerbatimBlock($m[1], $m[3] ?? '');
                        $blockStack[] = $verbatim;
                        if ($verbatim) {
                            $verbatimBlocks++;
                        }
                    }
                } elseif (preg_match('#^<!--\s+/wp:([a-z0-9/-]+)\s+-->$#', $raw) === 1) {
                    $was = array_pop($blockStack);
                    if ($was === true) {
                        $verbatimBlocks--;
                    }
                }
                continue;
            }
            if ($verbatimBlocks > 0) {
                $parts[] = $raw;
                continue;
            }
            if ($type === 'boundary') {
                $this->flush($run, $parts);
                $parts[] = $raw;
                continue;
            }
            if ($type === 'text') {
                $run[] = ['text', $raw, ''];
                continue;
            }
            if ($type === 'open' && $this->isVerbatimElement($name, $raw)) {
                $j = $this->matchingClose($tokens, $i, $name);
                $chunk = '';
                for ($k = $i; $k <= $j; $k++) {
                    $chunk .= $tokens[$k][1];
                }
                $i = $j;
                if (in_array($name, self::INLINE, true)) {
                    $run[] = ['atom', $chunk, $name];
                } else {
                    $this->flush($run, $parts);
                    $parts[] = $chunk;
                }
                continue;
            }
            if ($name === 'img') {
                $raw = $this->extractAlt($raw);
            }
            if (in_array($name, self::INLINE, true)) {
                $run[] = [$type, $raw, $name];
                continue;
            }
            $this->flush($run, $parts);
            $parts[] = $raw;
        }
        $this->flush($run, $parts);

        // Merge adjacent literals.
        $merged = [];
        foreach ($parts as $part) {
            $last = count($merged) - 1;
            if (is_string($part) && $last >= 0 && is_string($merged[$last])) {
                $merged[$last] .= $part;
            } else {
                $merged[] = $part;
            }
        }

        return array_values($merged);
    }

    private function isVerbatimBlock(string $name, string $json): bool
    {
        if (!str_contains($name, '/')) {
            $name = 'core/' . $name;
        }
        if (in_array($name, $this->verbatimBlocks, true)) {
            return true;
        }
        if ($json !== '') {
            $attrs = json_decode($json, true);
            if (is_array($attrs)) {
                if (array_key_exists('translate', $attrs) && ($attrs['translate'] === false || $attrs['translate'] === 'no')) {
                    return true;
                }
                $class = (string) ($attrs['className'] ?? '');
                if (preg_match('/(^|\s)notranslate(\s|$)/', $class) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isVerbatimElement(string $name, string $raw): bool
    {
        if (in_array($name, self::VERBATIM, true)) {
            return true;
        }
        if (preg_match('/\stranslate\s*=\s*(["\']?)no\1/i', $raw) === 1) {
            return true;
        }

        return preg_match('/\sclass\s*=\s*(["\'])[^"\']*\bnotranslate\b[^"\']*\1/i', $raw) === 1;
    }

    /**
     * @param list<array{0:string,1:string,2:string}> $tokens
     */
    private function matchingClose(array $tokens, int $start, string $name): int
    {
        if (in_array($name, self::VOID, true) || str_ends_with(rtrim($tokens[$start][1]), '/>')) {
            return $start;
        }
        $depth = 0;
        $count = count($tokens);
        for ($k = $start; $k < $count; $k++) {
            [$type, , $n] = $tokens[$k];
            if ($n !== $name) {
                continue;
            }
            if ($type === 'open') {
                $depth++;
            } elseif ($type === 'close') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Replaces registered shortcodes with sentinels. Caption-like
     * shortcodes become boundaries so their inner text is translated; all
     * other shortcodes become atoms copied verbatim.
     */
    private function protectShortcodes(string $html): string
    {
        $caption = array_values(array_intersect($this->captionShortcodes, $this->shortcodes));
        if ($caption !== []) {
            $names = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $caption));
            $html = (string) preg_replace_callback(
                '/\[\/?(?:' . $names . ')\b[^\]]*\]/',
                fn (array $m): string => $this->sentinel(self::BOUNDARY, $m[0]),
                $html
            );
        }
        $others = array_values(array_diff($this->shortcodes, $caption));
        if ($others === []) {
            return $html;
        }
        $names = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), $others));
        // Same shape as the core shortcode pattern: optional [[escape]],
        // self-closing, or enclosing with a matching closing tag.
        $re = '/\[(\[?)(' . $names . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)'
            . '(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/s';

        return (string) preg_replace_callback(
            $re,
            fn (array $m): string => $this->sentinel(self::ATOM, $m[0]),
            $html
        );
    }

    private function sentinel(string $kind, string $raw): string
    {
        $n = count($this->sentinels) + 1;
        $this->sentinels[$n] = $raw;

        return $kind . $n . self::END;
    }

    /**
     * @return list<array{0:string,1:string,2:string}> type, raw, tag name
     */
    private function tokenize(string $html): array
    {
        $re = '/<!--.*?-->|<\/?[a-zA-Z][a-zA-Z0-9:-]*(?:\s+(?:[^>"\']|"[^"]*"|\'[^\']*\')*)?\s*\/?>|'
            . preg_quote(self::BOUNDARY, '/') . '\d+' . preg_quote(self::END, '/') . '/su';
        if (preg_match_all($re, $html, $delims, PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Could not tokenise the post body.');
        }
        $tokens = [];
        $pos = 0;
        foreach ($delims[0] as [$raw, $offset]) {
            if ($offset > $pos) {
                $tokens[] = ['text', substr($html, $pos, $offset - $pos), ''];
            }
            $tokens[] = $this->classify($raw);
            $pos = $offset + strlen($raw);
        }
        if ($pos < strlen($html)) {
            $tokens[] = ['text', substr($html, $pos), ''];
        }

        return $tokens;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function classify(string $raw): array
    {
        if (str_starts_with($raw, '<!--')) {
            return ['comment', $raw, ''];
        }
        if (str_starts_with($raw, self::BOUNDARY)) {
            return ['boundary', $raw, ''];
        }
        preg_match('/^<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $raw, $m);
        $name = strtolower($m[2] ?? '');
        if (($m[1] ?? '') === '/') {
            return ['close', $raw, $name];
        }
        if (in_array($name, self::VOID, true) || str_ends_with($raw, '/>')) {
            return ['void', $raw, $name];
        }

        return ['open', $raw, $name];
    }

    private function extractAlt(string $raw): string
    {
        if (!$this->translateAlt) {
            return $raw;
        }

        return (string) preg_replace_callback(
            '/(\salt\s*=\s*)("([^"]*)"|\'([^\']*)\')/i',
            function (array $m) use ($raw): string {
                $value = ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '');
                $plain = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($plain === '' || preg_match('/\p{L}/u', $plain) !== 1) {
                    return $m[0];
                }
                $n = count($this->alts) + 1;
                $id = 'alt' . $n;
                $attachment = null;
                if (preg_match('/wp-image-(\d+)/', $raw, $am) === 1) {
                    $attachment = (int) $am[1];
                } elseif (preg_match('/data-id\s*=\s*["\']?(\d+)/', $raw, $am) === 1) {
                    $attachment = (int) $am[1];
                }
                $this->segments[$id] = new Segment($id, 'alt', $plain, Text::hash($plain), [], $attachment);
                $this->alts[$n] = ['segment' => $id, 'value' => $value];
                $quote = $m[2][0];

                return $m[1] . $quote . self::ALT . $n . self::END . $quote;
            },
            $raw,
            1
        );
    }

    /**
     * Turns the current run into a segment, or into literal markup when it
     * has no letters.
     *
     * @param list<array{0:string,1:string,2:string}> $run
     * @param list<string|array{seg:string}> $parts
     */
    private function flush(array &$run, array &$parts): void
    {
        if ($run === []) {
            return;
        }
        $raw = '';
        foreach ($run as $item) {
            $raw .= $item[1];
        }
        $text = '';
        $placeholders = [];
        $stack = [];
        $n = 0;
        foreach ($run as [$type, $itemRaw, $name]) {
            if ($type === 'text') {
                $text .= $this->textWithAtoms($itemRaw, $placeholders, $n);
            } elseif ($type === 'open') {
                $n++;
                $placeholders[$n] = ['open' => $itemRaw, 'close' => null];
                $stack[] = [$n, $name];
                $text .= '{' . $n . '}';
            } elseif ($type === 'close') {
                $found = null;
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][1] === $name) {
                        $found = $s;
                        break;
                    }
                }
                if ($found === null) {
                    $n++;
                    $placeholders[$n] = ['open' => $itemRaw, 'close' => null];
                    $text .= '{' . $n . '/}';
                    continue;
                }
                while (count($stack) - 1 > $found) {
                    $top = array_pop($stack);
                    if ($top === null) {
                        break;
                    }
                    $text = str_replace('{' . $top[0] . '}', '{' . $top[0] . '/}', $text);
                }
                $top = array_pop($stack);
                if ($top === null) {
                    continue;
                }
                $num = $top[0];
                $placeholders[$num] = ['open' => $placeholders[$num]['open'] ?? '', 'close' => $itemRaw];
                $text .= '{/' . $num . '}';
            } else {
                $n++;
                $placeholders[$n] = ['open' => $itemRaw, 'close' => null];
                $text .= '{' . $n . '/}';
            }
        }
        foreach ($stack as [$orphan]) {
            $text = str_replace('{' . $orphan . '}', '{' . $orphan . '/}', $text);
        }
        $run = [];

        $letters = (string) preg_replace('/\{\/?\d+\/?\}/', '', $text);
        if (preg_match('/\p{L}/u', $letters) !== 1) {
            $parts[] = $raw;

            return;
        }
        $lead = '';
        $trail = '';
        if (preg_match('/^[ \t\r\n]+/', $text, $lm) === 1) {
            $lead = $lm[0];
        }
        if (preg_match('/[ \t\r\n]+$/', $text, $tm) === 1) {
            $trail = $tm[0];
        }
        $core = substr($text, strlen($lead), strlen($text) - strlen($lead) - strlen($trail));
        $this->bodyCounter++;
        $id = 's' . $this->bodyCounter;
        $this->segments[$id] = new Segment($id, 'body', $core, Text::hash($core), $placeholders);
        if ($lead !== '') {
            $parts[] = $lead;
        }
        $parts[] = ['seg' => $id];
        if ($trail !== '') {
            $parts[] = $trail;
        }
    }

    /**
     * Decodes text and replaces URLs, e-mail addresses, shortcode atoms and
     * anything that looks like a placeholder with atom placeholders.
     *
     * @param array<int, array{open:string, close:?string}> $placeholders
     */
    private function textWithAtoms(string $raw, array &$placeholders, int &$n): string
    {
        $re = '/' . preg_quote(self::ATOM, '/') . '\d+' . preg_quote(self::END, '/')
            . '|\bhttps?:\/\/[^\s<>"\']+[^\s<>"\'.,;:!?)\]]'
            . '|\bwww\.[^\s<>"\']+[^\s<>"\'.,;:!?)\]]'
            . '|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'
            . '|\{\/?\d+\/?\}/u';
        $out = '';
        $pos = 0;
        if (preg_match_all($re, $raw, $m, PREG_OFFSET_CAPTURE) === false) {
            throw new \RuntimeException('Could not scan segment text.');
        }
        foreach ($m[0] as [$match, $offset]) {
            $out .= self::decode(substr($raw, $pos, $offset - $pos));
            $n++;
            $placeholders[$n] = ['open' => $match, 'close' => null];
            $out .= '{' . $n . '/}';
            $pos = $offset + strlen($match);
        }

        return $out . self::decode(substr($raw, $pos));
    }

    private static function decode(string $raw): string
    {
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
