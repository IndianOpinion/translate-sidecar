<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * The model adapter for sites without indianopinion/llm-json-php: a site
 * supplies a caller through the `translate_sidecar_llm_caller` filter and
 * owns the transport and credentials. Same prompt, schema and reply checks
 * as LlmJsonTranslator.
 *
 * The caller has the signature
 * `fn(string $system, string $user, array $jsonSchema, array $meta): array`
 * and returns the decoded JSON object. It may add a `_meta` key with
 * `finish_reason`, `provider`, `model` and `usage`. It throws
 * TranslatorException to say whether a retry may help; any other
 * exception is treated as retryable.
 */
final class CallerTranslator implements Translator
{
    /** @var callable */
    private $caller;

    public function __construct(callable $caller, private readonly int $maxChars = 6000)
    {
        $this->caller = $caller;
    }

    public function id(): string
    {
        return 'caller';
    }

    public function label(): string
    {
        return 'Site caller (language model, JSON)';
    }

    public function maxCharsPerCall(): int
    {
        return $this->maxChars;
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        $messages = PromptBuilder::messages($request);
        $meta = [
            'job_id' => $request->jobId,
            'purpose' => LlmJsonTranslator::PURPOSE,
            'caller' => 'translate-sidecar',
            'target_language' => $request->targetLang,
        ];
        try {
            $reply = ($this->caller)($messages[0]['content'], $messages[1]['content'], PromptBuilder::schema(), $meta);
        } catch (TranslatorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TranslatorException('Caller failed: ' . $e->getMessage(), 'caller_error', true, false, $e);
        }

        return ModelReply::parse($reply);
    }
}
