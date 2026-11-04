<?php

namespace Database\Seeders;

use App\Models\SasihDay;
use App\Models\Source;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Seeder;

/**
 * Curated 2026 sasih rows. Purnama and Tilem dates follow kalenderbali.org.
 * Nyepi, Ngembak Geni, Tawur/Pengerupukan, and Siwa Ratri follow the Bimas Hindu 2026 list.
 * This is not an astronomical calculation.
 */
class SasihCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $sourceId = Source::query()->where('code', 'kalenderbali-2026')->value('id');
        $anchors = [
            ['2026-01-03', 'purnama', 'Kepitu'],
            ['2026-01-18', 'tilem', 'Kepitu'],
            ['2026-02-02', 'purnama', 'Kewulu'],
            ['2026-02-16', 'tilem', 'Kewulu'],
            ['2026-03-03', 'purnama', 'Kesanga'],
            ['2026-03-18', 'tilem', 'Kesanga'],
            ['2026-04-02', 'purnama', 'Kedasa'],
            ['2026-04-16', 'tilem', 'Kedasa'],
            ['2026-05-01', 'purnama', 'Jiyestha'],
            ['2026-05-16', 'tilem', 'Jiyestha'],
            ['2026-05-31', 'purnama', 'Sadha'],
            ['2026-06-14', 'tilem', 'Sadha'],
            ['2026-06-29', 'purnama', 'Kasa'],
            ['2026-07-14', 'tilem', 'Kasa'],
            ['2026-07-29', 'purnama', 'Karo'],
            ['2026-08-12', 'tilem', 'Karo'],
            ['2026-08-27', 'purnama', 'Ketiga'],
            ['2026-09-11', 'tilem', 'Ketiga'],
            ['2026-09-26', 'purnama', 'Kapat'],
            ['2026-10-11', 'tilem', 'Kapat'],
            ['2026-10-25', 'purnama', 'Kelima'],
            ['2026-11-09', 'tilem', 'Kelima'],
            ['2026-11-24', 'purnama', 'Kenam'],
            ['2026-12-09', 'tilem', 'Kenam'],
            ['2026-12-23', 'purnama', 'Kepitu'],
        ];
        $events = array_map(function (array $row) {
            return [
                'date' => new DateTimeImmutable($row[0]),
                'kind' => $row[1],
                'sasih' => $row[2],
            ];
        }, $anchors);

        $extra = [
            '2026-01-17' => ['siwa_ratri'],
            '2026-03-18' => ['tawur_kesanga', 'pengerupukan'],
            '2026-03-19' => ['nyepi'],
            '2026-03-20' => ['ngembak_geni'],
        ];

        $cursor = new DateTimeImmutable('2026-01-01');
        $end = new DateTimeImmutable('2026-12-31');
        $batch = [];
        while ($cursor <= $end) {
            $iso = $cursor->format('Y-m-d');
            $row = $this->describe($cursor, $events);
            $batch[] = [
                'date' => $iso,
                'sasih' => $row['sasih'],
                'penanggal_pangelong' => $row['phase'].' '.$row['number'],
                'is_purnama' => $row['kind'] === 'purnama',
                'is_tilem' => $row['kind'] === 'tilem',
                'observances' => json_encode($extra[$iso] ?? []),
                'source_id' => $sourceId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        foreach (array_chunk($batch, 50) as $chunk) {
            SasihDay::query()->upsert($chunk, ['date'], [
                'sasih', 'penanggal_pangelong', 'is_purnama', 'is_tilem', 'observances', 'source_id', 'updated_at',
            ]);
        }
    }

    /**
     * @param  list<array{date: DateTimeImmutable, kind: string, sasih: string}>  $events
     * @return array{sasih: string, phase: string, number: int, kind: string|null}
     */
    private function describe(DateTimeImmutable $day, array $events): array
    {
        $previous = null;
        $next = null;
        foreach ($events as $event) {
            if ($event['date'] <= $day) {
                $previous = $event;
            }
            if ($event['date'] >= $day && $next === null) {
                $next = $event;
            }
        }

        if ($previous !== null && $previous['date']->format('Y-m-d') === $day->format('Y-m-d')) {
            if ($previous['kind'] === 'purnama') {
                $number = $this->penanggalNumber($day, $events, $previous);

                return ['sasih' => $previous['sasih'], 'phase' => 'penanggal', 'number' => $number, 'kind' => 'purnama'];
            }
            $start = $this->previousOfKind($events, $previous['date'], 'purnama');
            $number = $start === null ? 15 : $this->daysBetween($start->modify('+1 day'), $day);

            return ['sasih' => $previous['sasih'], 'phase' => 'pangelong', 'number' => $number, 'kind' => 'tilem'];
        }

        if ($next !== null && $next['kind'] === 'purnama') {
            return [
                'sasih' => $next['sasih'],
                'phase' => 'penanggal',
                'number' => $this->penanggalNumber($day, $events, $next),
                'kind' => null,
            ];
        }

        $tilem = $next !== null && $next['kind'] === 'tilem' ? $next : $previous;
        $purnama = $this->previousOfKind($events, $day, 'purnama');
        $start = $purnama?->modify('+1 day') ?? $day;

        return [
            'sasih' => $tilem['sasih'] ?? 'Kepitu',
            'phase' => 'pangelong',
            'number' => $this->daysBetween($start, $day),
            'kind' => null,
        ];
    }

    /**
     * @param  list<array{date: DateTimeImmutable, kind: string, sasih: string}>  $events
     * @param  array{date: DateTimeImmutable, kind: string, sasih: string}  $purnama
     */
    private function penanggalNumber(DateTimeImmutable $day, array $events, array $purnama): int
    {
        $tilem = $this->previousOfKind($events, $purnama['date']->modify('-1 day'), 'tilem');
        if ($tilem === null) {
            return 15 - $this->daysBetween($day, $purnama['date']) + 1;
        }

        return $this->daysBetween($tilem->modify('+1 day'), $day);
    }

    /**
     * @param  list<array{date: DateTimeImmutable, kind: string, sasih: string}>  $events
     */
    private function previousOfKind(array $events, DateTimeImmutable $beforeOrOn, string $kind): ?DateTimeImmutable
    {
        $found = null;
        foreach ($events as $event) {
            if ($event['kind'] === $kind && $event['date'] <= $beforeOrOn) {
                $found = $event['date'];
            }
        }

        return $found;
    }

    private function daysBetween(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return (int) $start->diff($end)->days + 1;
    }
}
