<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * Human translation. Never called by the job runner; translations are typed
 * or pasted on the review screen and stored as candidates.
 */
final class ManualTranslator implements Translator
{
    public function id(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Manual (human translator)';
    }

    public function maxCharsPerCall(): int
    {
        return PHP_INT_MAX;
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        throw TranslatorException::fatal('The manual adapter does not translate automatically. Use the review screen.', 'manual_only');
    }
}
