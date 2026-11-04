<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use InvalidArgumentException;

/**
 * Turns a published wariga phrase into engine conditions.
 * Example: "Buda Keliwon Penanggal 12".
 */
final class DewasaClause
{
    /** @var list<string> */
    private const SAPTA = ['Redite', 'Soma', 'Anggara', 'Buda', 'Wraspati', 'Sukra', 'Saniscara'];

    /** @var list<string> */
    private const PANCA = ['Umanis', 'Paing', 'Pon', 'Wage', 'Keliwon'];

    /** @var list<string> */
    private const SASIH = [
        'Kasa', 'Karo', 'Ketiga', 'Kapat', 'Kelima', 'Kenam', 'Kepitu', 'Kewulu',
        'Kesanga', 'Kedasa', 'Jiyestha', 'Sadha',
    ];

    /**
     * @param  list<string>  $clauses
     * @return array<string, mixed>
     */
    public static function any(array $clauses): array
    {
        $parsed = array_map(self::one(...), $clauses);

        return count($parsed) === 1 ? $parsed[0] : ['any' => $parsed];
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(string $clause): array
    {
        $parts = preg_split('/\s+/', trim($clause)) ?: [];
        $all = [];
        for ($i = 0; $i < count($parts); $i++) {
            $word = $parts[$i];
            if (in_array($word, self::SAPTA, true)) {
                $all[] = ['saptawara' => $word];
            } elseif (in_array($word, self::PANCA, true)) {
                $all[] = ['pancawara' => $word];
            } elseif (in_array($word, CalendarEngine::WUKU, true)) {
                $all[] = ['wuku' => $word];
            } elseif (in_array($word, self::SASIH, true)) {
                $all[] = ['sasih' => $word];
            } elseif (strcasecmp($word, 'Penanggal') === 0) {
                $all[] = ['penanggal' => (int) ($parts[++$i] ?? 0)];
            } elseif (strcasecmp($word, 'Pangelong') === 0) {
                $all[] = ['pangelong' => (int) ($parts[++$i] ?? 0)];
            } elseif (strcasecmp($word, 'Purnama') === 0) {
                $all[] = ['is_purnama' => true];
            } elseif (strcasecmp($word, 'Tilem') === 0) {
                $all[] = ['is_tilem' => true];
            } else {
                throw new InvalidArgumentException('Unknown dewasa token "'.$word.'" in "'.$clause.'"');
            }
        }
        if ($all === []) {
            throw new InvalidArgumentException('Empty dewasa clause: '.$clause);
        }

        return ['all' => $all];
    }
}
