<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Tests\Support;

use IndianOpinion\TranslateSidecar\Translator\TranslationRequest;
use IndianOpinion\TranslateSidecar\Translator\TranslationResponse;
use IndianOpinion\TranslateSidecar\Translator\Translator;

/**
 * Records every request and answers with a pseudo translation into the
 * target script, or with whatever the test's strategy returns.
 */
final class SpyTranslator implements Translator
{
    /** @var list<TranslationRequest> */
    public array $calls = [];
    /** @var (\Closure(TranslationRequest, int): TranslationResponse)|null */
    public ?\Closure $strategy = null;

    public function __construct(public int $maxChars = 6000)
    {
    }

    public function id(): string
    {
        return 'spy';
    }

    public function label(): string
    {
        return 'Spy';
    }

    public function maxCharsPerCall(): int
    {
        return $this->maxChars;
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        $this->calls[] = $request;
        if ($this->strategy !== null) {
            return ($this->strategy)($request, count($this->calls));
        }

        return new TranslationResponse(
            array_map(static fn (string $t): string => Pseudo::translate($t, $request->targetLang), $request->segments),
            $request->targetLang,
            TranslationResponse::FINISH_STOP,
            'spy',
            'spy-1'
        );
    }

    /**
     * @return list<string>
     */
    public function sentTexts(): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            foreach ($c->segments as $t) {
                $out[] = $t;
            }
        }

        return $out;
    }
}
