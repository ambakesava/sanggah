<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class PromptLibrary
{
    public function get(string $name): string
    {
        $path = base_path('prompts/'.$name.'.md');
        if (! is_file($path)) {
            return '';
        }
        $text = file_get_contents($path);

        return is_string($text) ? trim($text) : '';
    }
}
