<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

/**
 * A translation put back together: plain text fields, sanitised body HTML
 * and the alt text map.
 */
final class Assembled
{
    /**
     * @param array<int, string> $altMap attachment id to translated alt
     * @param array<string, string> $segmentTexts segment id to translated text
     */
    public function __construct(
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $seoTitle,
        public readonly string $metaDescription,
        public readonly string $content,
        public readonly array $altMap,
        public readonly array $segmentTexts,
    ) {
    }

    /**
     * Plain text of the translated body segments, for guards.
     */
    public function bodyText(): string
    {
        $out = [];
        foreach ($this->segmentTexts as $id => $text) {
            if (preg_match('/^s\d+$/', (string) $id) === 1) {
                $out[] = trim((string) preg_replace('/\{\/?\d+\/?\}/', ' ', $text));
            }
        }

        return implode("\n", $out);
    }
}
