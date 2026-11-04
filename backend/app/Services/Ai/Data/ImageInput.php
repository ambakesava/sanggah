<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

final class ImageInput
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly ?string $filename = null,
    ) {}

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }
}
