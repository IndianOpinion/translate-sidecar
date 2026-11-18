<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * Model adapter: sends a generic prompt and a strict JSON schema through a
 * caller, then validates the shape of the reply.
 *
 * The caller has the signature
 * `fn(string $system, string $user, array $jsonSchema, array $meta): array`
 * and returns the decoded JSON object. It may add a `_meta` key with
 * `finish_reason`, `provider`, `model` and `usage`. Supply it with the
 * `translate_sidecar_llm_caller` filter, for example as a thin wrapper
 * around indianopinion/llm-json-php or the WordPress HTTP API.
 */
class LlmJsonTranslator implements Translator
{
    /** @var callable */
    private $caller;

    public function __construct(callable $caller, private readonly int $maxChars = 6000)
    {
        $this->caller = $caller;
    }

    public function id(): string
    {
        return 'llm-json';
    }

    public function label(): string
    {
        return 'Language model (JSON)';
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
            'purpose' => 'translate_sidecar.translate',
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

        return self::parse($reply);
    }

    /**
     * Validates the decoded reply and builds a response.
     */
    public static function parse(mixed $reply): TranslationResponse
    {
        if (!is_array($reply) || !isset($reply['segments']) || !is_array($reply['segments'])) {
            throw TranslatorException::fatal('Reply is not an object with a segments list.', 'bad_shape');
        }
        $segments = [];
        $dupes = [];
        foreach ($reply['segments'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['text']) || !is_scalar($item['id']) || !is_string($item['text'])) {
                throw TranslatorException::fatal('A segment is not an object with string id and text.', 'bad_shape');
            }
            $id = (string) $item['id'];
            if (array_key_exists($id, $segments)) {
                $dupes[] = $id;
                continue;
            }
            $segments[$id] = $item['text'];
        }
        $meta = isset($reply['_meta']) && is_array($reply['_meta']) ? $reply['_meta'] : [];
        $finish = (string) ($meta['finish_reason'] ?? TranslationResponse::FINISH_STOP);
        if (!in_array($finish, [TranslationResponse::FINISH_STOP, TranslationResponse::FINISH_LENGTH, TranslationResponse::FINISH_OTHER], true)) {
            $finish = TranslationResponse::FINISH_OTHER;
        }
        $usage = isset($meta['usage']) && is_array($meta['usage']) ? $meta['usage'] : [];

        return new TranslationResponse(
            $segments,
            isset($reply['language']) && is_string($reply['language']) && $reply['language'] !== '' ? $reply['language'] : null,
            $finish,
            (string) ($meta['provider'] ?? ''),
            (string) ($meta['model'] ?? ''),
            [
                'input_units' => (int) ($usage['input_units'] ?? $usage['input_tokens'] ?? 0),
                'output_units' => (int) ($usage['output_units'] ?? $usage['output_tokens'] ?? 0),
            ],
            $dupes,
        );
    }
}
