<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Keyword intent parser used only by MockProvider.
 * It extracts explicit dates and relative phrases. It does not compute pawukon.
 */
final class OfflineIntentParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $question, string $lang): array
    {
        $text = trim($question);
        $lower = mb_strtolower($text);
        $date = $this->explicitDate($lower);
        $relative = $this->relative($lower);
        $activity = $this->activity($lower);
        $rerahinan = $this->rerahinan($lower);
        $year = $this->year($lower);

        $intent = 'out_of_scope';
        if (! $this->looksCultural($lower)) {
            return [
                'intent' => 'out_of_scope',
                'activity' => null,
                'date_iso' => null,
                'date_from' => null,
                'date_to' => null,
                'relative_expr' => null,
                'rerahinan' => null,
                'year' => null,
                'lang' => $lang === 'en' ? 'en' : 'id',
            ];
        }
        if ($this->containsAny($lower, ['hari baik', 'hari terbaik', 'hari bagus', 'best day', 'good day', 'dewasa ayu', 'auspicious'])
            || ($activity !== null && $this->containsAny($lower, ['kapan', 'when', 'deket', 'dekat', 'terdekat', 'nearest', 'hari ']))) {
            $intent = 'hari_baik';
        } elseif ($this->containsAny($lower, ['upacara', 'ceremony', 'hari raya', 'holiday'])) {
            $intent = 'cek_upacara';
        } elseif ($rerahinan !== null && $this->containsAny($lower, ['kapan', 'when', 'berikutnya', 'next'])) {
            $intent = 'rerahinan_berikutnya';
        } elseif ($rerahinan !== null && $date === null && $relative === null) {
            $intent = 'rerahinan_berikutnya';
        } elseif ($date !== null || $relative !== null || $this->containsAny($lower, ['wuku', 'pawukon', 'saptawara', 'kalender', 'calendar'])) {
            $intent = 'info_tanggal';
        }

        return [
            'intent' => $intent,
            'activity' => $activity,
            'date_iso' => $date,
            'date_from' => null,
            'date_to' => null,
            'relative_expr' => $relative,
            'rerahinan' => $rerahinan,
            'year' => $year,
            'lang' => $lang === 'en' ? 'en' : 'id',
        ];
    }

    private function year(string $lower): ?int
    {
        if (preg_match('/\b(20\d{2})\b/', $lower, $match) !== 1) {
            return null;
        }
        $year = (int) $match[1];

        return ($year >= 2020 && $year <= 2035) ? $year : null;
    }

    private function looksCultural(string $lower): bool
    {
        return $this->containsAny($lower, [
            'bali', 'upacara', 'ceremony', 'hari ', 'hari?', 'wuku', 'kalender', 'calendar',
            'galungan', 'kuningan', 'nyepi', 'pagerwesi', 'saraswati', 'sasih', 'dewasa',
            'usaha', 'business', 'pindah', 'tanggal', 'june', 'juni', 'juli', 'pura', 'temple',
            'pawukon', 'saptawara', 'nikah', 'menikah', 'kawin', 'wedding', 'marry',
        ]);
    }

    private function activity(string $lower): ?string
    {
        if ($this->containsAny($lower, ['buka usaha', 'membuka usaha', 'open a business', 'open a shop', 'start a business'])) {
            return 'buka_usaha';
        }
        if ($this->containsAny($lower, ['pindah rumah', 'move house', 'pindah'])) {
            return 'pindah_rumah';
        }
        if ($this->containsAny($lower, ['menanam', 'tanam', 'plant'])) {
            return 'menanam';
        }
        if ($this->containsAny($lower, ['menikah', 'pernikahan', 'perkawinan', 'pawiwahan', 'nikah', 'kawin', 'wedding', 'marry', 'marriage'])) {
            return 'nikah';
        }

        return null;
    }

    private function rerahinan(string $lower): ?string
    {
        $map = [
            'kajeng keliwon' => 'kajeng_keliwon',
            'tumpek landep' => 'tumpek_landep',
            'tumpek uduh' => 'tumpek_uduh',
            'tumpek wayang' => 'tumpek_wayang',
            'siwa ratri' => 'siwa_ratri',
            'siwaratri' => 'siwa_ratri',
            'ngembak geni' => 'ngembak_geni',
            'pengerupukan' => 'pengerupukan',
            'pagerwesi' => 'pagerwesi',
            'saraswati' => 'saraswati',
            'galungan' => 'galungan',
            'kuningan' => 'kuningan',
            'nyepi' => 'nyepi',
            'purnama' => 'purnama',
            'tilem' => 'tilem',
        ];
        foreach ($map as $needle => $code) {
            if (str_contains($lower, $needle)) {
                return $code;
            }
        }

        return null;
    }

    private function relative(string $lower): ?string
    {
        if ($this->containsAny($lower, ['paling deket', 'paling dekat', 'terdekat', 'deket', 'nearest', 'soonest'])) {
            return 'terdekat';
        }
        foreach (['minggu depan', 'next week', 'minggu ini', 'this week', 'bulan depan', 'next month', 'besok', 'tomorrow', 'lusa', 'hari ini', 'today'] as $phrase) {
            if (str_contains($lower, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    private function explicitDate(string $lower): ?string
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $lower, $match) === 1) {
            return $this->iso((int) $match[1], (int) $match[2], (int) $match[3]);
        }
        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
            'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'mei' => 5, 'juni' => 6, 'juli' => 7,
            'agustus' => 8, 'oktober' => 10, 'desember' => 12,
        ];
        $pattern = '/\b(\d{1,2})\s+('.implode('|', array_keys($months)).')\s+(\d{4})\b/i';
        if (preg_match($pattern, $lower, $match) === 1) {
            return $this->iso((int) $match[3], $months[mb_strtolower($match[2])] ?? 0, (int) $match[1]);
        }
        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})\b/', $lower, $match) === 1) {
            return $this->iso((int) $match[3], (int) $match[2], (int) $match[1]);
        }

        return null;
    }

    private function iso(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $year.'-'.$month.'-'.$day);
        if ($date === false || $date->format('Y-n-j') !== $year.'-'.$month.'-'.$day) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
