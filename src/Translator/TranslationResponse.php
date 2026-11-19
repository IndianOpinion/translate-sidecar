<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * What a translator returned. Immutable.
 */
final class TranslationResponse
{
    public const FINISH_STOP = 'stop';
    public const FINISH_LENGTH = 'length';
    public const FINISH_OTHER = 'other';

    /**
     * @param array<string, string> $segments segment id to translated text
     * @param array{input_units?:int, output_units?:int, raw?:mixed} $usage
     * @param list<string> $duplicateIds ids the translator returned more than once
     */
    public function __construct(
        public readonly array $segments,
        public readonly ?string $declaredLang = null,
        public readonly string $finishReason = self::FINISH_STOP,
        public readonly string $provider = '',
        public readonly string $model = '',
        public readonly array $usage = [],
        public readonly array $duplicateIds = [],
    ) {
    }
}
