<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * Reads the decoded JSON reply of a model translator into a response.
 * Shared by LlmJsonTranslator and CallerTranslator, so both apply the same
 * shape checks (case 98).
 */
final class ModelReply
{
    /**
     * Validates the decoded reply and builds a response. An optional
     * `_meta` key may carry `finish_reason`, `provider`, `model` and
     * `usage`.
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
