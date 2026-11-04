<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ceremony;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CeremonyController extends Controller
{
    public function index(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $from = $request->query('from');
        $to = $request->query('to');
        if (! is_string($from) || $from === '') {
            $from = now()->timezone(config('sanggah.timezone'))->toDateString();
        }
        if (! is_string($to) || $to === '') {
            $to = now()->timezone(config('sanggah.timezone'))->addDays(90)->toDateString();
        }
        $key = 'ceremonies:'.$lang.':'.$from.':'.$to;
        $payload = Cache::remember($key, (int) config('sanggah.cache_ttl.ceremonies'), function () use ($lang, $from, $to) {
            $rows = Ceremony::query()
                ->where('status', 'published')
                ->whereDate('date_start', '<=', $to)
                ->where(function ($query) use ($from) {
                    $query->whereDate('date_end', '>=', $from)->orWhereNull('date_end');
                })
                ->orderBy('date_start')
                ->get();

            return $rows->map(fn (Ceremony $ceremony) => [
                'slug' => $ceremony->slug,
                'name' => $ceremony->nameFor($lang),
                'type' => $ceremony->type,
                'date_start' => $ceremony->date_start->toDateString(),
                'date_end' => $ceremony->date_end?->toDateString(),
                'summary' => $lang === 'en' ? $ceremony->summary_en : $ceremony->summary_id,
                'has_road_note' => filled($lang === 'en' ? $ceremony->road_note_en : $ceremony->road_note_id),
            ])->all();
        });

        return ApiResponse::ok([
            'data' => $payload,
            'meta' => ['count' => count($payload), 'from' => $from, 'to' => $to],
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $lang = Locale::fromRequest($request);
        $ceremony = Ceremony::query()->with('sources')->where('slug', $slug)->where('status', 'published')->first();
        if ($ceremony === null) {
            return ApiResponse::error('NOT_FOUND', ApiResponse::message('NOT_FOUND', $lang), 404);
        }

        return ApiResponse::ok(['data' => $this->detail($ceremony, $lang)]);
    }

    public function ics(string $slug)
    {
        $ceremony = Ceremony::query()->where('slug', $slug)->where('status', 'published')->first();
        if ($ceremony === null) {
            return ApiResponse::error('NOT_FOUND', ApiResponse::message('NOT_FOUND', 'id'), 404);
        }
        $start = $ceremony->date_start->format('Ymd');
        $end = ($ceremony->date_end ?? $ceremony->date_start)->copy()->addDay()->format('Ymd');
        $summary = $this->icsText($ceremony->name_id);
        $description = $this->icsText(trim($ceremony->summary_id."\n".$ceremony->tips_id));
        $body = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sanggah//Bali Culture Companion//ID',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:'.$ceremony->slug.'@sanggah',
            'DTSTART;VALUE=DATE:'.$start,
            'DTEND;VALUE=DATE:'.$end,
            'SUMMARY:'.$summary,
            'DESCRIPTION:'.$description,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$ceremony->slug.'.ics"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Ceremony $ceremony, string $lang): array
    {
        return [
            'slug' => $ceremony->slug,
            'name' => $ceremony->nameFor($lang),
            'type' => $ceremony->type,
            'date_start' => $ceremony->date_start->toDateString(),
            'date_end' => $ceremony->date_end?->toDateString(),
            'summary' => $lang === 'en' ? $ceremony->summary_en : $ceremony->summary_id,
            'tips' => $lang === 'en' ? $ceremony->tips_en : $ceremony->tips_id,
            'road_note' => $lang === 'en' ? $ceremony->road_note_en : $ceremony->road_note_id,
            'road_note_label' => $lang === 'en' ? 'Curated info, not real-time' : 'Informasi kurasi, bukan real-time',
            'sources' => $ceremony->sources->map(fn ($source) => [
                'title' => $source->title,
                'publisher' => $source->publisher,
                'url' => $source->url,
            ])->all(),
        ];
    }

    private function icsText(string $text): string
    {
        $text = str_replace(["\r\n", "\n", "\r"], '\n', $text);

        return str_replace([',', ';'], ['\,', '\;'], $text);
    }
}
