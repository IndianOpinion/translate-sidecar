<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

/**
 * One unit of text sent for translation.
 */
final class Segment
{
    /**
     * @param string $field one of title, excerpt, seo_title, meta_description, body, alt
     * @param array<int, array{open:string, close:?string}> $placeholders token number to markup
     */
    public function __construct(
        public readonly string $id,
        public readonly string $field,
        public readonly string $text,
        public readonly string $hash,
        public readonly array $placeholders = [],
        public readonly ?int $attachmentId = null,
    ) {
    }

    /**
     * Source text with placeholder tokens removed.
     */
    public function plainText(): string
    {
        return trim((string) preg_replace('/\{\/?\d+\/?\}/', ' ', $this->text));
    }

    public function length(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }
}
