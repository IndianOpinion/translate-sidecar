<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

use IndianOpinion\TranslateSidecar\Support\Text;

/**
 * Puts translated segments back into the original structure.
 *
 * Model output is untrusted: tags and sentinel characters are stripped from
 * every translated text, the text is escaped, placeholder tokens are swapped
 * back for the original markup, and the body is sanitised with the post
 * content rules before it is returned.
 */
final class Reassembler
{
    public const DERIVED_EXCERPT_LENGTH = 300;

    /**
     * @param array<string, string> $translations segment id to translated text
     */
    public function assemble(SegmentedPost $post, array $translations): Assembled
    {
        $clean = [];
        foreach ($post->segments as $id => $segment) {
            if (!array_key_exists($id, $translations)) {
                throw new PlaceholderException(sprintf('No translation for segment %s.', $id));
            }
            $clean[$id] = self::cleanText((string) $translations[$id]);
        }

        $body = '';
        foreach ($post->bodyParts as $part) {
            if (is_string($part)) {
                $body .= $part;
                continue;
            }
            $segment = $post->segments[$part['seg']];
            $body .= self::restore($clean[$segment->id], $segment);
        }
        $altMap = [];
        foreach ($post->alts as $n => $alt) {
            $value = $alt['value'];
            $segId = $alt['segment'];
            if ($segId !== null && isset($clean[$segId])) {
                $plain = self::plainField($clean[$segId]);
                $value = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $attachment = $post->segments[$segId]->attachmentId;
                if ($attachment !== null) {
                    $altMap[$attachment] = $plain;
                }
            }
            $body = str_replace(Segmenter::ALT . $n . Segmenter::END, $value, $body);
        }
        $body = self::restoreSentinels($body, $post->sentinels);
        $body = function_exists('wp_kses_post') ? (string) wp_kses_post($body) : $body;

        $title = isset($clean['title']) ? self::plainField($clean['title']) : $post->fields['title'];
        $excerpt = isset($clean['excerpt']) ? self::plainField($clean['excerpt']) : '';
        if ($excerpt === '' && !$post->hasManualExcerpt) {
            $excerpt = Text::trimGraphemes(Text::plain($body), self::DERIVED_EXCERPT_LENGTH);
        }

        return new Assembled(
            $title,
            $excerpt,
            isset($clean['seo_title']) ? self::plainField($clean['seo_title']) : '',
            isset($clean['meta_description']) ? self::plainField($clean['meta_description']) : '',
            $body,
            $altMap,
            $clean,
        );
    }

    /**
     * Removes markup and sentinel characters from model output, keeping
     * placeholder tokens.
     */
    public static function cleanText(string $text): string
    {
        $text = str_replace([Segmenter::ATOM, Segmenter::BOUNDARY, Segmenter::ALT, Segmenter::END], '', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('#<(script|style|iframe|object|template)\b[^>]*>.*?</\1\s*>#si', '', $text);
        $text = (string) preg_replace('#<(script|style|iframe|object|template)\b[^>]*>#si', '', $text);
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);

        return (string) preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $text);
    }

    private static function plainField(string $text): string
    {
        $text = (string) preg_replace('/\{\/?\d+\/?\}/', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Escapes translated text and swaps placeholder tokens for markup.
     */
    public static function restore(string $text, Segment $segment): string
    {
        $out = '';
        $pos = 0;
        preg_match_all('/\{(\/?)(\d+)(\/?)\}/', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $match) {
            [$token, $offset] = $match[0];
            $out .= htmlspecialchars(substr($text, $pos, $offset - $pos), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $pos = $offset + strlen($token);
            $num = (int) $match[2][0];
            $ph = $segment->placeholders[$num] ?? null;
            if ($ph === null) {
                throw new PlaceholderException(sprintf('Unknown placeholder %s in segment %s.', $token, $segment->id));
            }
            if ($match[1][0] === '/') {
                $out .= (string) $ph['close'];
            } else {
                $out .= $ph['open'];
            }
        }

        return $out . htmlspecialchars(substr($text, $pos), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<int, string> $sentinels
     */
    private static function restoreSentinels(string $body, array $sentinels): string
    {
        // Sentinels may nest (a shortcode inside a caption), so repeat until stable.
        for ($pass = 0; $pass < 3; $pass++) {
            $before = $body;
            $body = (string) preg_replace_callback(
                '/[' . Segmenter::ATOM . Segmenter::BOUNDARY . '](\d+)' . Segmenter::END . '/u',
                static fn (array $m): string => $sentinels[(int) $m[1]] ?? '',
                $body
            );
            if ($body === $before) {
                break;
            }
        }

        return $body;
    }
}
