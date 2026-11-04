<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

final class AiResponse
{
    /**
     * @param  array<string, mixed>|null  $json
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $model,
        public readonly string $provider,
        public readonly bool $cached = false,
    ) {}
}
