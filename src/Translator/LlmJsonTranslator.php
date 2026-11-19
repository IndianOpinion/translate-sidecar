<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

use IndianOpinion\LlmJson\Attempt;
use IndianOpinion\LlmJson\Chain;
use IndianOpinion\LlmJson\Client;
use IndianOpinion\LlmJson\CreditExhaustion;
use IndianOpinion\LlmJson\Exception\ChainExhaustedException;
use IndianOpinion\LlmJson\Exception\ConfigurationException;
use IndianOpinion\LlmJson\Exception\CreditExhaustedException;
use IndianOpinion\LlmJson\Exception\LlmJsonException;
use IndianOpinion\LlmJson\Options;
use IndianOpinion\LlmJson\Outcome;
use IndianOpinion\LlmJson\Provider;
use IndianOpinion\LlmJson\Request;
use IndianOpinion\LlmJson\Route;
use IndianOpinion\LlmJson\Schema;
use IndianOpinion\LlmJson\Store\WordPressTransientStore;
use IndianOpinion\LlmJson\Transport\WordPressTransport;

/**
 * Model adapter built on indianopinion/llm-json-php (PRD 5.1). Sends the
 * generic prompt with a strict JSON schema through an llm-json-php Client
 * and maps the library's typed failures onto TranslatorException, so the
 * runner retries, fails or pauses exactly as for any other adapter.
 *
 * The client comes from the `translate_sidecar_llm_client` filter, or is
 * built from constants (or environment variables of the same name):
 * `TRANSLATE_SIDECAR_LLM_KEY`, `TRANSLATE_SIDECAR_LLM_MODEL` (one model or
 * a comma-separated fallback chain) and, for any chat completions server
 * other than OpenRouter, `TRANSLATE_SIDECAR_LLM_BASE_URL`.
 */
final class LlmJsonTranslator implements Translator
{
    public const PURPOSE = 'translate_sidecar.translate';

    public function __construct(private readonly Client $client, private readonly int $maxChars = 6000)
    {
    }

    /**
     * True when the library is installed (through Composer or its own
     * bootstrap file).
     */
    public static function available(): bool
    {
        return class_exists(Client::class);
    }

    public function id(): string
    {
        return 'llm-json';
    }

    public function label(): string
    {
        return 'Language model (llm-json-php)';
    }

    public function maxCharsPerCall(): int
    {
        return $this->maxChars;
    }

    public function translate(TranslationRequest $request): TranslationResponse
    {
        try {
            $call = Request::for(self::PURPOSE, Schema::fromArray('translation', PromptBuilder::schema()))
                ->withMessages(PromptBuilder::messages($request));
            $result = $this->client->complete($call);
        } catch (CreditExhaustedException $e) {
            $providers = array_map(static fn (CreditExhaustion $c): string => $c->provider, $e->exhaustedProviders());
            throw new TranslatorException('Provider credit is exhausted: ' . implode(', ', array_unique($providers)) . '.', 'credit_exhausted', false, true, $e);
        } catch (ChainExhaustedException $e) {
            throw self::fromChain($e);
        } catch (ConfigurationException $e) {
            throw new TranslatorException('llm-json-php configuration: ' . $e->getMessage(), 'configuration', false, false, $e);
        } catch (LlmJsonException $e) {
            throw new TranslatorException('llm-json-php: ' . $e->getMessage(), 'llm_json_error', true, false, $e);
        }

        $data = $result->data();
        $usage = $result->totalUsage();
        $data['_meta'] = [
            'finish_reason' => TranslationResponse::FINISH_STOP,
            'provider' => $result->provider(),
            'model' => $result->model(),
            'usage' => [
                'input_units' => (int) $usage->promptTokens,
                'output_units' => (int) $usage->completionTokens,
            ],
        ];

        return ModelReply::parse($data);
    }

    /**
     * Maps an exhausted chain to the runner's terms from the last attempt.
     * Messages name outcomes and status codes only: never a body, never a
     * key.
     */
    public static function fromChain(ChainExhaustedException $e): TranslatorException
    {
        $attempts = $e->attempts();
        $last = $attempts !== [] ? $attempts[array_key_last($attempts)] : null;
        if (!$last instanceof Attempt) {
            // Every route skipped (cooldown) or the deadline ran out before
            // any attempt: try again later.
            return new TranslatorException('llm-json-php: no attempt was made (' . $e->reason() . ').', 'no_attempt', true, false, $e);
        }
        $message = sprintf('llm-json-php: %s after %d attempt(s), last %s on %s%s.', $e->reason(), count($attempts), $last->outcome->value, $last->model, $last->httpStatus !== null ? ' (HTTP ' . $last->httpStatus . ')' : '');
        if ($e->isDeadline()) {
            return new TranslatorException($message, 'deadline', true, false, $e);
        }

        return match ($last->outcome) {
            Outcome::Truncated => new TranslatorException($message, 'truncated', false, false, $e),
            Outcome::Malformed, Outcome::SchemaViolation => new TranslatorException($message, 'bad_shape', false, false, $e),
            Outcome::CreditExhausted => new TranslatorException($message, 'credit_exhausted', false, true, $e),
            default => new TranslatorException($message, $last->outcome->value, $last->outcome->isRetryable(), false, $e),
        };
    }

    /**
     * A client built from constants or environment variables, or null when
     * the key or the model is missing. Uses the WordPress HTTP API and
     * transients when WordPress is loaded. One attempt per route and a two
     * minute deadline: the plugin's own backoff handles retries, and a
     * WP-Cron tick must not hang.
     */
    public static function clientFromConstants(): ?Client
    {
        if (!self::available()) {
            return null;
        }
        $key = self::setting('TRANSLATE_SIDECAR_LLM_KEY');
        $models = array_values(array_filter(array_map('trim', explode(',', self::setting('TRANSLATE_SIDECAR_LLM_MODEL')))));
        if ($key === '' || $models === []) {
            return null;
        }
        $base = self::setting('TRANSLATE_SIDECAR_LLM_BASE_URL');
        $provider = $base !== ''
            ? Provider::generic('custom', $base, $key)->withStructuredOutputs(false, true)
            : Provider::openRouter($key);
        $chain = Chain::of(...array_map(static fn (string $model): Route => Route::to($provider, $model), $models));
        $client = Client::create($chain, function_exists('wp_remote_post') ? new WordPressTransport() : null)
            ->withOptions(Options::defaults()->withAttemptsPerRoute(1)->withDeadline(120));
        if (function_exists('get_transient')) {
            $client = $client->withStore(new WordPressTransientStore());
        }

        return $client;
    }

    private static function setting(string $name): string
    {
        if (defined($name)) {
            return trim((string) constant($name));
        }
        $env = getenv($name);

        return is_string($env) ? trim($env) : '';
    }
}
