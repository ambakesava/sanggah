<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class AiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 503,
        public readonly bool $retryable = true,
    ) {
        parent::__construct($message);
    }

    public static function unavailable(string $message = 'AI service is temporarily unavailable.'): self
    {
        return new self('AI_UNAVAILABLE', $message, 503, true);
    }

    public static function timeout(): self
    {
        return new self('AI_TIMEOUT', 'The AI provider timed out.', 504, true);
    }

    public static function upstream(string $message = 'The AI provider returned an error.'): self
    {
        return new self('AI_UPSTREAM_ERROR', $message, 502, true);
    }

    public static function budget(): self
    {
        return new self('AI_BUDGET_EXCEEDED', 'The daily AI budget has been reached.', 503, false);
    }
}
