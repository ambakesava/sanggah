<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $fallback
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly bool $retryable = false,
        public readonly ?array $fallback = null,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
