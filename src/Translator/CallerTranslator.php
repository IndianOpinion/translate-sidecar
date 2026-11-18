<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * The model adapter registered when a site supplies a caller through the
 * `translate_sidecar_llm_caller` filter. Same prompt, schema and checks as
 * LlmJsonTranslator; the site owns the transport and credentials.
 */
final class CallerTranslator extends LlmJsonTranslator
{
    public function id(): string
    {
        return 'caller';
    }

    public function label(): string
    {
        return 'Site caller (language model, JSON)';
    }
}
