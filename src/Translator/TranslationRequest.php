<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * One call's worth of segments. Immutable.
 */
final class TranslationRequest
{
    /**
     * @param array<string, string> $segments ordered map of segment id to text with placeholder tokens
     * @param array{title?:string, post_type?:string, glossary?:array<string, string>, do_not_translate?:list<string>} $context
     */
    public function __construct(
        public readonly string $sourceLang,
        public readonly string $targetLang,
        public readonly array $segments,
        public readonly array $context = [],
        public readonly int $jobId = 0,
    ) {
    }

    public function chars(): int
    {
        $n = 0;
        foreach ($this->segments as $text) {
            $n += mb_strlen($text, 'UTF-8');
        }

        return $n;
    }
}
