<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the public rerainan list on kalenderbali.org.
 * The site does not publish a JSON API. A blocked response is cached briefly
 * so one Cloudflare challenge does not stall every question.
 */
final class KalenderBaliClient
{
    /**
     * @return list<array{date: string, name: string, url: string}>
     */
    public function year(int $year): array
    {
        if (Cache::get($this->blockKey($year))) {
            return [];
        }

        $events = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthEvents = $this->month($year, $month);
            if ($monthEvents === null) {
                Cache::put($this->blockKey($year), true, now()->addMinutes(30));

                return [];
            }
            $events = array_merge($events, $monthEvents);
        }

        return $events;
    }

    /**
     * @return list<array{date: string, name: string, url: string}>
     */
    public function matching(string $code, int $year): array
    {
        $needles = match ($code) {
            'galungan' => ['hari raya galungan'],
            'kuningan' => ['hari raya kuningan'],
            'nyepi' => ['nyepi'],
            'pagerwesi' => ['pagerwesi'],
            'saraswati' => ['saraswati'],
            'siwa_ratri' => ['siwa ratri', 'siwaratri'],
            default => [str_replace('_', ' ', $code)],
        };
        $hits = [];
        foreach ($this->year($year) as $event) {
            $name = mb_strtolower($event['name']);
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    $hits[] = $event;
                    break;
                }
            }
        }

        return $hits;
    }

    /**
     * @return list<array{date: string, name: string, url: string}>|null Null means the site blocked the request.
     */
    public function month(int $year, int $month): ?array
    {
        $key = "kalenderbali:rerainan:{$year}:{$month}";
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('events', $cached)) {
            return $cached['blocked'] ? null : $cached['events'];
        }

        $url = config('sanggah.kalenderbali.base_url').'/rerainan.php?bl='.$month.'&th='.$year;
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Sanggah/1.0; +https://kalenderbali.org/)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
            ])->timeout((int) config('sanggah.kalenderbali.timeout', 8))->get($url);
        } catch (Throwable) {
            Cache::put($key, ['blocked' => true, 'events' => []], now()->addMinutes(30));

            return null;
        }

        $body = $response->body();
        if (! $response->successful() || $this->isChallenge($body)) {
            Cache::put($key, ['blocked' => true, 'events' => []], now()->addMinutes(30));

            return null;
        }

        $events = $this->parse($body, $url);
        Cache::put($key, ['blocked' => false, 'events' => $events], now()->addHours(12));

        return $events;
    }

    /**
     * @return list<array{date: string, name: string, url: string}>
     */
    public function parse(string $html, string $sourceUrl): array
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r", "\xc2\xa0"], ["\n", ' '], $text);
        $months = 'Januari|Februari|Pebruari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|Nopember|November|Desember';
        $pattern = '/(\d{1,2})\s+('.$months.')\s+(\d{4})\.\s*([^.\n<]{2,120})/u';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        $events = [];
        $seen = [];
        foreach ($matches as $match) {
            $month = $this->monthNumber($match[2]);
            $iso = $this->iso((int) $match[3], $month, (int) $match[1]);
            $name = trim(preg_replace('/\s+/', ' ', $match[4]) ?? '');
            if ($iso === null || $name === '') {
                continue;
            }
            $key = $iso.'|'.mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $events[] = [
                'date' => $iso,
                'name' => $name,
                'url' => $sourceUrl,
            ];
        }

        return $events;
    }

    private function isChallenge(string $body): bool
    {
        $sample = strtolower(substr($body, 0, 1500));

        return str_contains($sample, 'just a moment')
            || str_contains($sample, 'cf-browser-verification')
            || str_contains($sample, 'challenge-platform');
    }

    private function monthNumber(string $name): int
    {
        return match (mb_strtolower($name)) {
            'januari' => 1,
            'februari', 'pebruari' => 2,
            'maret' => 3,
            'april' => 4,
            'mei' => 5,
            'juni' => 6,
            'juli' => 7,
            'agustus' => 8,
            'september' => 9,
            'oktober' => 10,
            'nopember', 'november' => 11,
            'desember' => 12,
            default => 0,
        };
    }

    private function iso(int $year, int $month, int $day): ?string
    {
        if ($month < 1 || $month > 12) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-n-j', $year.'-'.$month.'-'.$day);

        return $date !== false && $date->format('Y-n-j') === $year.'-'.$month.'-'.$day
            ? $date->format('Y-m-d')
            : null;
    }

    private function blockKey(int $year): string
    {
        return "kalenderbali:blocked:{$year}";
    }
}
