<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * A translation backend: an LLM, a machine translation API, or a person.
 */
interface Translator
{
    /** Short slug, for example "llm-json" or "manual". */
    public function id(): string;

    public function label(): string;

    /**
     * @throws TranslatorException on any provider failure
     */
    public function translate(TranslationRequest $request): TranslationResponse;

    /** Hint for the size of one call, in source characters. */
    public function maxCharsPerCall(): int;
}
