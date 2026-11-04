<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;

interface AiService
{
    /**
     * @param  list<array{slug: string, name: string, visual_hint: string}>  $classes
     */
    public function identify(ImageInput $image, string $lang, array $classes): ScanResult;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array;

    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(string $system, string $user, ?array $schema = null): AiResponse;

    public function providerName(): string;
}
