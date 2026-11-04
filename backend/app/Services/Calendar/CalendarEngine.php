<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Deterministic Balinese Pawukon calendar.
 *
 * Epoch 2020-07-05 is pawukon day 1: Redite Paing, wuku Sinta.
 * That Gregorian date is the start of the cycle Dershowitz & Reingold
 * anchor on Julian Day Number 146 (see also the published 210-row table).
 * The engine never calls an LLM. Sasih / Nyepi come only from the curated row
 * passed in by the caller.
 */
final class CalendarEngine
{
    public const EPOCH = '2020-07-05';

    public const TIMEZONE = 'Asia/Makassar';

    /** @var list<string> */
    public const WUKU = [
        'Sinta', 'Landep', 'Ukir', 'Kulantir', 'Tolu', 'Gumbreg', 'Wariga', 'Warigadean',
        'Julungwangi', 'Sungsang', 'Dunggulan', 'Kuningan', 'Langkir', 'Medangsia', 'Pujut', 'Pahang',
        'Krulut', 'Merakih', 'Tambir', 'Medangkungan', 'Matal', 'Uye', 'Menail', 'Prangbakat', 'Bala',
        'Ugu', 'Wayang', 'Kelawu', 'Dukut', 'Watugunung',
    ];

    /** @var list<string> */
    public const TRIWARA = ['Pasah', 'Beteng', 'Kajeng'];

    /** @var list<string> */
    public const PANCAWARA = ['Paing', 'Pon', 'Wage', 'Keliwon', 'Umanis'];

    /** @var list<string> */
    public const SADWARA = ['Tungleh', 'Aryang', 'Urukung', 'Paniron', 'Was', 'Maulu'];

    /** @var list<string> */
    public const SAPTAWARA = ['Redite', 'Soma', 'Anggara', 'Buda', 'Wraspati', 'Sukra', 'Saniscara'];

    /** @var list<string> */
    public const CATURWARA = ['Sri', 'Laba', 'Jaya', 'Menala'];

    /** @var list<string> */
    public const ASTAWARA = ['Sri', 'Indra', 'Guru', 'Yama', 'Ludra', 'Brahma', 'Kala', 'Uma'];

    /** @var list<string> */
    public const SANGAWARA = ['Dangu', 'Jangur', 'Gigis', 'Nohan', 'Ogan', 'Erangan', 'Urungan', 'Tulus', 'Dadi'];

    /** @var array<int, string> */
    private const DASAWARA_BY_URIP = [
        1 => 'Pandita',
        2 => 'Pati',
        3 => 'Suka',
        4 => 'Duka',
        5 => 'Sri',
        6 => 'Manuh',
        7 => 'Manusa',
        8 => 'Raja',
        9 => 'Dewa',
        10 => 'Raksasa',
    ];

    /** @var list<int> */
    private const PANCA_URIP = [9, 7, 4, 8, 5];

    /** @var list<int> */
    private const SAPTA_URIP = [5, 4, 3, 7, 8, 6, 9];

    /**
     * Proleptic Gregorian day count. Timezone offsets (including historical
     * ones in Asia/Makassar) must not change the day number.
     */
    public static function gregorianDayNumber(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    }

    public function pawukonIndex(DateTimeInterface $date): int
    {
        $zone = new DateTimeZone(self::TIMEZONE);
        $day = DateTimeImmutable::createFromInterface($date)->setTimezone($zone);
        $diff = self::gregorianDayNumber((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'))
            - self::gregorianDayNumber(2020, 7, 5);
        $mod = $diff % 210;
        if ($mod < 0) {
            $mod += 210;
        }

        return $mod;
    }

    /**
     * @param  array<string, mixed>|null  $sasih
     * @return array<string, mixed>
     */
    public function describe(DateTimeInterface $date, ?array $sasih = null): array
    {
        $zone = new DateTimeZone(self::TIMEZONE);
        $day = DateTimeImmutable::createFromInterface($date)->setTimezone($zone)->setTime(0, 0);
        $index = $this->pawukonIndex($day);
        $pawukonDay = $index + 1;
        $pancaIndex = $index % 5;
        $saptaIndex = $index % 7;
        $tri = self::TRIWARA[$index % 3];
        $panca = self::PANCAWARA[$pancaIndex];
        $sapta = self::SAPTAWARA[$saptaIndex];
        $wuku = self::WUKU[intdiv($index, 7)];

        $urip = self::PANCA_URIP[$pancaIndex] + self::SAPTA_URIP[$saptaIndex] + 1;
        if ($urip > 10) {
            $urip -= 10;
        }

        $label = isset($sasih['penanggal_pangelong']) && is_string($sasih['penanggal_pangelong'])
            ? $sasih['penanggal_pangelong']
            : null;
        $phase = null;
        $phaseDay = null;
        if ($label !== null && preg_match('/^(penanggal|pangelong)\s+(\d+)$/', $label, $matches) === 1) {
            $phase = $matches[1];
            $phaseDay = (int) $matches[2];
        }

        $observances = [];
        foreach ((array) ($sasih['observances'] ?? []) as $name) {
            if (is_string($name) && $name !== '') {
                $observances[] = $name;
            }
        }

        $rerahinan = $this->pawukonRerahinan($pawukonDay, $wuku, $sapta, $panca, $tri);
        if (! empty($sasih['is_purnama'])) {
            $rerahinan[] = 'purnama';
        }
        if (! empty($sasih['is_tilem'])) {
            $rerahinan[] = 'tilem';
        }
        foreach ($observances as $name) {
            if (! in_array($name, $rerahinan, true)) {
                $rerahinan[] = $name;
            }
        }

        return [
            'date' => $day->format('Y-m-d'),
            'pawukon_day' => $pawukonDay,
            'wuku' => $wuku,
            'wuku_number' => intdiv($index, 7) + 1,
            'ekawara' => ($urip % 2 === 0) ? 'Luang' : null,
            'dwiwara' => ($urip % 2 === 0) ? 'Pepet' : 'Menga',
            'triwara' => $tri,
            'caturwara' => self::CATURWARA[$this->caturwaraIndex($index)],
            'pancawara' => $panca,
            'sadwara' => self::SADWARA[$index % 6],
            'saptawara' => $sapta,
            'astawara' => self::ASTAWARA[$this->astawaraIndex($index)],
            'sangawara' => self::SANGAWARA[$this->sangawaraIndex($index)],
            'dasawara' => self::DASAWARA_BY_URIP[$urip],
            'urip' => $urip,
            'sasih' => $sasih['sasih'] ?? null,
            'penanggal_pangelong' => $label,
            'phase' => $phase,
            'phase_day' => $phaseDay,
            'is_purnama' => (bool) ($sasih['is_purnama'] ?? false),
            'is_tilem' => (bool) ($sasih['is_tilem'] ?? false),
            'rerahinan' => array_values($rerahinan),
        ];
    }

    public function caturwaraIndex(int $index): int
    {
        if ($index < 71) {
            return $index % 4;
        }
        if ($index <= 72) {
            return 2;
        }

        return ($index - 2) % 4;
    }

    public function astawaraIndex(int $index): int
    {
        if ($index < 71) {
            return $index % 8;
        }
        if ($index <= 72) {
            return 6;
        }

        return ($index - 2) % 8;
    }

    public function sangawaraIndex(int $index): int
    {
        if ($index <= 3) {
            return 0;
        }

        return ($index - 3) % 9;
    }

    /**
     * @return list<string>
     */
    private function pawukonRerahinan(int $day, string $wuku, string $sapta, string $panca, string $tri): array
    {
        $names = [];
        $fixed = [
            4 => 'pagerwesi',
            71 => 'penyekeban_galungan',
            72 => 'penyajaan_galungan',
            73 => 'penampahan_galungan',
            74 => 'galungan',
            75 => 'umanis_galungan',
            83 => 'penampahan_kuningan',
            84 => 'kuningan',
            210 => 'saraswati',
        ];
        if (isset($fixed[$day])) {
            $names[] = $fixed[$day];
        }
        if ($tri === 'Kajeng' && $panca === 'Keliwon') {
            $names[] = 'kajeng_keliwon';
        }
        if ($sapta === 'Saniscara' && $panca === 'Keliwon') {
            $tumpek = [
                'Landep' => 'tumpek_landep',
                'Wariga' => 'tumpek_uduh',
                'Krulut' => 'tumpek_krulut',
                'Uye' => 'tumpek_kandang',
                'Wayang' => 'tumpek_wayang',
            ];
            if (isset($tumpek[$wuku])) {
                $names[] = $tumpek[$wuku];
            }
        }
        if ($sapta === 'Buda' && $panca === 'Wage') {
            $names[] = 'buda_cemeng';
        }
        if ($sapta === 'Anggara' && $panca === 'Keliwon') {
            $names[] = 'anggara_kasih';
        }

        return $names;
    }
}
