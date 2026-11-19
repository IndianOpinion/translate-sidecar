<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Segment;

use IndianOpinion\TranslateSidecar\Support\Text;

/**
 * The source fields of a post that are translated.
 */
final class SourceFields
{
    public function __construct(
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt = '',
        public readonly string $seoTitle = '',
        public readonly string $metaDescription = '',
    ) {
    }

    /**
     * SHA-256 of the normalised source fields. Changes when the title,
     * body, excerpt or the SEO fields change.
     */
    public function hash(): string
    {
        return hash('sha256', implode("\x1F", [
            Text::normalise($this->title),
            Text::normalise($this->content),
            Text::normalise($this->excerpt),
            Text::normalise($this->seoTitle),
            Text::normalise($this->metaDescription),
        ]));
    }
}
