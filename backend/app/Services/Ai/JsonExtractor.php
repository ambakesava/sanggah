<?php

declare(strict_types=1);

namespace App\Services\Ai;

final class JsonExtractor
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
