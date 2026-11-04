<?php

declare(strict_types=1);

namespace App\Services\Rag;

final class CitationValidator
{
    public function sanitize(string $text, int $maxN): string
    {
        if ($maxN < 1) {
            return '';
        }
        $parts = preg_split('/(?<=[\.\!\?])\s+|\n+/u', trim($text)) ?: [];
        $kept = [];
        foreach ($parts as $part) {
            $sentence = trim($part);
            if ($sentence === '' || preg_match('/\p{L}/u', $sentence) !== 1) {
                continue;
            }
            if (preg_match_all('/\[(\d+)\]/', $sentence, $matches) < 1) {
                continue;
            }
            $valid = true;
            foreach ($matches[1] as $number) {
                $n = (int) $number;
                if ($n < 1 || $n > $maxN) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $kept[] = $sentence;
            }
        }

        return trim(implode(' ', $kept));
    }

    public function hasCitation(string $text): bool
    {
        return preg_match('/\[\d+\]/', $text) === 1;
    }
}
