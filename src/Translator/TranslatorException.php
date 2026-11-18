<?php

declare(strict_types=1);

namespace IndianOpinion\TranslateSidecar\Translator;

/**
 * A translator failed. Says whether a retry may help and whether the
 * account is out of credit.
 */
final class TranslatorException extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        string $message,
        string $errorCode = 'provider_error',
        public readonly bool $retryable = false,
        public readonly bool $creditExhausted = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = substr($errorCode, 0, 64);
    }

    public static function retryable(string $message, string $code = 'provider_error'): self
    {
        return new self($message, $code, true);
    }

    public static function fatal(string $message, string $code = 'provider_error'): self
    {
        return new self($message, $code, false);
    }

    public static function creditExhausted(string $message): self
    {
        return new self($message, 'credit_exhausted', false, true);
    }

    /**
     * Maps an HTTP status to an exception: 429 and 5xx retry, 402 means
     * credit is exhausted, every other 4xx fails at once.
     */
    public static function fromHttpStatus(int $status, string $message = ''): self
    {
        $message = $message !== '' ? $message : sprintf('Provider answered HTTP %d.', $status);
        if ($status === 402) {
            return self::creditExhausted($message);
        }
        if ($status === 408 || $status === 429 || $status >= 500) {
            return self::retryable($message, 'http_' . $status);
        }

        return self::fatal($message, 'http_' . $status);
    }
}
