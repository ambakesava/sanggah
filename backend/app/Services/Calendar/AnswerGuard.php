<?php

declare(strict_types=1);

namespace App\Services\Calendar;

/**
 * Rejects a prose answer that introduces a date, wuku, or holy day
 * that is not present in the engine payload.
 */
final class AnswerGuard
{
    /**
     * @param  list<array<string, mixed>>  $days
     */
    public function accepts(string $answer, array $days): bool
    {
        if (trim($answer) === '' || $days === []) {
            return false;
        }

        $allowedDates = [];
        $allowed = [];
        foreach ($days as $day) {
            $allowedDates[(string) $day['date']] = true;
            foreach (['wuku', 'saptawara', 'pancawara', 'triwara', 'caturwara', 'astawara', 'sasih', 'dasawara'] as $key) {
                if (! empty($day[$key]) && is_string($day[$key])) {
                    $allowed[mb_strtolower($day[$key])] = true;
                }
            }
            foreach ((array) ($day['rerahinan'] ?? []) as $name) {
                if (! is_string($name)) {
                    continue;
                }
                $allowed[mb_strtolower($name)] = true;
                $allowed[mb_strtolower(str_replace('_', ' ', $name))] = true;
                if (str_starts_with($name, 'tumpek')) {
                    $allowed['tumpek'] = true;
                }
                if (str_contains($name, 'penampahan')) {
                    $allowed['penampahan'] = true;
                }
            }
        }

        foreach ($this->datesIn($answer) as $iso) {
            if (! isset($allowedDates[$iso])) {
                return false;
            }
        }

        foreach (CalendarEngine::WUKU as $name) {
            if ($this->mentions($answer, $name) && ! isset($allowed[mb_strtolower($name)])) {
                return false;
            }
        }
        foreach (array_merge(CalendarEngine::SAPTAWARA, CalendarEngine::PANCAWARA) as $name) {
            if ($this->mentions($answer, $name) && ! isset($allowed[mb_strtolower($name)])) {
                return false;
            }
        }

        $festivals = [
            'galungan' => 'galungan',
            'kuningan' => 'kuningan',
            'pagerwesi' => 'pagerwesi',
            'saraswati' => 'saraswati',
            'nyepi' => 'nyepi',
            'kajeng keliwon' => 'kajeng keliwon',
            'siwa ratri' => 'siwa_ratri',
            'siwaratri' => 'siwa_ratri',
            'purnama' => 'purnama',
            'tilem' => 'tilem',
            'ngembak geni' => 'ngembak_geni',
            'pengerupukan' => 'pengerupukan',
        ];
        $lower = mb_strtolower($answer);
        foreach ($festivals as $needle => $token) {
            if (! str_contains($lower, $needle)) {
                continue;
            }
            $token = str_replace('_', ' ', $token);
            if (! isset($allowed[$token]) && ! isset($allowed[str_replace(' ', '_', $token)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function datesIn(string $answer): array
    {
        $found = [];
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/', $answer, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $iso = $this->iso((int) $match[1], (int) $match[2], (int) $match[3]);
                if ($iso !== null) {
                    $found[] = $iso;
                }
            }
        }

        $month = 'january|february|march|april|may|june|july|august|september|october|november|december|januari|februari|maret|mei|juni|juli|agustus|oktober|desember';
        if (preg_match_all('/\b(\d{1,2})\s+('.$month.')\s+(\d{4})\b/i', $answer, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $iso = $this->iso((int) $match[3], $this->monthNumber($match[2]), (int) $match[1]);
                if ($iso !== null) {
                    $found[] = $iso;
                }
            }
        }
        if (preg_match_all('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})\b/', $answer, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $iso = $this->iso((int) $match[3], (int) $match[2], (int) $match[1]);
                if ($iso !== null) {
                    $found[] = $iso;
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function mentions(string $answer, string $name): bool
    {
        return preg_match('/\b'.preg_quote($name, '/').'\b/i', $answer) === 1;
    }

    private function monthNumber(string $name): int
    {
        return match (mb_strtolower($name)) {
            'january', 'januari' => 1,
            'february', 'februari' => 2,
            'march', 'maret' => 3,
            'april' => 4,
            'may', 'mei' => 5,
            'june', 'juni' => 6,
            'july', 'juli' => 7,
            'august', 'agustus' => 8,
            'september' => 9,
            'october', 'oktober' => 10,
            'november' => 11,
            'december', 'desember' => 12,
            default => 0,
        };
    }

    private function iso(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $year.'-'.$month.'-'.$day);
        if ($date === false || $date->format('Y-n-j') !== $year.'-'.$month.'-'.$day) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
