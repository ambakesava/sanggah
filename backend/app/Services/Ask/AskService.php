<?php

declare(strict_types=1);

namespace App\Services\Ask;

use App\Exceptions\AiException;
use App\Exceptions\ApiProblem;
use App\Models\CalendarRule;
use App\Models\Ceremony;
use App\Models\SasihDay;
use App\Services\Ai\AiService;
use App\Services\Ai\OfflineIntentParser;
use App\Services\Ai\PromptLibrary;
use App\Services\Calendar\AnswerGuard;
use App\Services\Calendar\CalendarEngine;
use App\Services\Calendar\RelativeDateResolver;
use App\Services\Calendar\RuleEvaluator;
use App\Support\ApiResponse;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

final class AskService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly CalendarEngine $engine,
        private readonly RelativeDateResolver $dates,
        private readonly RuleEvaluator $rules,
        private readonly AnswerGuard $guard,
        private readonly PromptLibrary $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function ask(array $input, string $lang): array
    {
        $zone = new DateTimeZone(CalendarEngine::TIMEZONE);
        $today = Carbon::now($zone)->toDateTimeImmutable()->setTime(0, 0);
        $intent = $this->intent($input, $lang, $today);
        $disclaimer = (string) config('sanggah.disclaimer.calendar.'.$lang);

        if ($intent['intent'] === 'out_of_scope') {
            return [
                'intent' => $intent,
                'engine' => null,
                'answer' => $lang === 'en'
                    ? 'Sanggah answers questions about the Balinese calendar and holy days. Try: "Is there a ceremony on 17 June 2026?" or "A good day to open a shop next week?"'
                    : 'Sanggah menjawab pertanyaan tentang kalender Bali dan hari raya. Contoh: "Upacara apa tanggal 17 Juni 2026?" atau "Hari baik buka usaha minggu depan?"',
                'answer_mode' => 'template',
                'needs_form' => false,
                'disclaimer' => $disclaimer,
            ];
        }

        if ($intent['intent'] === 'needs_form') {
            return [
                'intent' => $intent,
                'engine' => null,
                'answer' => $lang === 'en'
                    ? 'The question could not be read as a date. Use the form: pick an activity and a date range of at most 62 days.'
                    : 'Pertanyaan belum terbaca sebagai tanggal. Gunakan formulir: pilih kegiatan dan rentang tanggal paling lama 62 hari.',
                'answer_mode' => 'template',
                'needs_form' => true,
                'disclaimer' => $disclaimer,
            ];
        }

        $span = $this->span($intent, $today, $lang);
        $days = $this->daysBetween($span['from'], $span['to']);
        $ruleRows = CalendarRule::query()->where('status', 'validated')->get()->map->toEngineArray()->all();
        $engineDays = [];
        foreach ($days as $day) {
            $iso = $day->format('Y-m-d');
            $described = $this->engine->describe($day, SasihDay::payloadFor($iso));
            $activity = $intent['intent'] === 'hari_baik' ? $intent['activity'] : null;
            $matched = $this->rules->match($described, $ruleRows, $activity);
            $described['rules'] = array_map(fn (array $rule) => [
                'code' => $rule['code'],
                'name' => $lang === 'en' ? $rule['name_en'] : $rule['name_id'],
                'effect' => $rule['effect'],
                'source_id' => $rule['source_id'],
            ], $matched);
            $described['ceremonies'] = Ceremony::query()
                ->where('status', 'published')
                ->whereDate('date_start', '<=', $iso)
                ->where(function ($query) use ($iso) {
                    $query->whereDate('date_end', '>=', $iso)->orWhereNull('date_end');
                })
                ->orderBy('date_start')
                ->get()
                ->map(fn (Ceremony $ceremony) => [
                    'slug' => $ceremony->slug,
                    'name' => $ceremony->nameFor($lang),
                ])->all();
            $described['recommended'] = $this->recommended($matched, $intent);
            $engineDays[] = $described;
        }

        $template = $this->template($intent, $engineDays, $lang, $disclaimer);
        $answer = $template;
        $mode = 'template';
        if ($this->ai->providerName() !== 'mock') {
            try {
                $system = $this->prompts->get('calendar');
                $user = json_encode([
                    'question' => $input['question'] ?? '',
                    'lang' => $lang,
                    'intent' => $intent,
                    'days' => $engineDays,
                ], JSON_UNESCAPED_UNICODE);
                $response = $this->ai->generate($system, (string) $user, ['title' => 'CalendarExplanation']);
                $text = trim($response->text);
                if (is_array($response->json)) {
                    $candidate = $response->json['answer'] ?? $response->json['text'] ?? null;
                    $text = is_string($candidate) ? trim($candidate) : '';
                }
                if ($text !== '' && $this->guard->accepts($text, $engineDays)) {
                    $answer = $text;
                    $mode = 'llm';
                }
            } catch (AiException | Throwable) {
                $answer = $template;
                $mode = 'template';
            }
        }
        if (! str_contains($answer, $lang === 'en' ? 'pemangku' : 'pemangku')) {
            $answer = rtrim($answer)."\n\n".$disclaimer;
        }

        return [
            'intent' => $intent,
            'engine' => [
                'days' => $engineDays,
                'rules_version' => (string) config('sanggah.rules_version'),
            ],
            'answer' => $answer,
            'answer_mode' => $mode,
            'needs_form' => false,
            'disclaimer' => $disclaimer,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function intent(array $input, string $lang, DateTimeImmutable $today): array
    {
        $question = (string) ($input['question'] ?? '');
        $keyword = $this->normalizeIntent((new OfflineIntentParser)->parse($question, $lang), $lang);
        $override = $input['intent_override'] ?? null;
        if (is_array($override)) {
            return $this->mergeIntent($this->normalizeIntent($override, $lang), $keyword);
        }
        if ($this->ai->providerName() === 'mock') {
            $response = $this->ai->generate(
                'CalendarIntent. Do not calculate dates.',
                "lang: {$lang}\nquestion: {$question}\ntoday: {$today->format('Y-m-d')}",
                ['title' => 'CalendarIntent']
            );

            return $this->mergeIntent($this->normalizeIntent($response->json ?? [], $lang), $keyword);
        }
        try {
            $response = $this->ai->generate(
                $this->prompts->get('intent'),
                "lang: {$lang}\nquestion: {$question}\ntoday: {$today->format('Y-m-d')}\nDo not calculate the Balinese calendar.",
                ['title' => 'CalendarIntent']
            );
            if (! is_array($response->json) || ! isset($response->json['intent'])) {
                return $keyword['intent'] === 'out_of_scope'
                    ? ['intent' => 'needs_form', 'activity' => null, 'date_iso' => null, 'date_from' => null, 'date_to' => null, 'relative_expr' => null, 'rerahinan' => null, 'lang' => $lang]
                    : $keyword;
            }

            return $this->mergeIntent($this->normalizeIntent($response->json, $lang), $keyword);
        } catch (Throwable) {
            return $keyword['intent'] === 'out_of_scope'
                ? ['intent' => 'needs_form', 'activity' => null, 'date_iso' => null, 'date_from' => null, 'date_to' => null, 'relative_expr' => null, 'rerahinan' => null, 'lang' => $lang]
                : $keyword;
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $keyword
     * @return array<string, mixed>
     */
    private function mergeIntent(array $parsed, array $keyword): array
    {
        if (in_array($parsed['intent'], ['out_of_scope', 'needs_form'], true) && ! in_array($keyword['intent'], ['out_of_scope', 'needs_form'], true)) {
            return $keyword;
        }
        if ($parsed['activity'] === null && is_string($keyword['activity'])) {
            $parsed['activity'] = $keyword['activity'];
        }
        if (($parsed['relative_expr'] === null || $parsed['relative_expr'] === '') && is_string($keyword['relative_expr'])) {
            $parsed['relative_expr'] = $keyword['relative_expr'];
        }
        if ($parsed['intent'] === 'info_tanggal' && $keyword['intent'] === 'hari_baik') {
            $parsed['intent'] = 'hari_baik';
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalizeIntent(array $raw, string $lang): array
    {
        $intent = (string) ($raw['intent'] ?? 'out_of_scope');
        $allowed = ['hari_baik', 'info_tanggal', 'cek_upacara', 'rerahinan_berikutnya', 'out_of_scope'];
        if (! in_array($intent, $allowed, true)) {
            $intent = 'needs_form';
        }
        $activity = $raw['activity'] ?? null;
        if (is_string($activity)) {
            $activity = str_replace([' ', '-'], '_', mb_strtolower(trim($activity)));
            $activity = [
                'menikah' => 'nikah',
                'pernikahan' => 'nikah',
                'perkawinan' => 'nikah',
                'kawin' => 'nikah',
                'pawiwahan' => 'nikah',
                'wedding' => 'nikah',
                'marry' => 'nikah',
                'marriage' => 'nikah',
            ][$activity] ?? $activity;
            if ($activity === '') {
                $activity = null;
            }
        } else {
            $activity = null;
        }
        $rerahinan = $raw['rerahinan'] ?? null;
        if (is_string($rerahinan)) {
            $rerahinan = str_replace([' ', '-'], '_', mb_strtolower(trim($rerahinan)));
        } else {
            $rerahinan = null;
        }

        return [
            'intent' => $intent,
            'activity' => $activity,
            'date_iso' => $this->cleanDate($raw['date_iso'] ?? null),
            'date_from' => $this->cleanDate($raw['date_from'] ?? null),
            'date_to' => $this->cleanDate($raw['date_to'] ?? null),
            'relative_expr' => is_string($raw['relative_expr'] ?? null) ? mb_strtolower(trim((string) $raw['relative_expr'])) : null,
            'rerahinan' => $rerahinan,
            'lang' => $lang,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{from: string, to: string}
     */
    private function span(array $intent, DateTimeImmutable $today, string $lang): array
    {
        if ($intent['intent'] === 'rerahinan_berikutnya') {
            $found = $this->findNext($intent['rerahinan'], $today, true);
            if ($found === null) {
                $found = $this->findNext($intent['rerahinan'], $today, false);
            }
            if ($found === null) {
                throw new ApiProblem(
                    'NOT_FOUND',
                    $lang === 'en'
                        ? 'That observance is not in the curated 2026 sasih table, and it is not a repeating pawukon day.'
                        : 'Hari itu tidak ada di tabel sasih 2026, dan bukan rerahinan pawukon yang berulang.',
                    404
                );
            }

            return ['from' => $found, 'to' => $found];
        }

        if (is_string($intent['date_from']) && is_string($intent['date_to'])) {
            return $this->guardSpan($intent['date_from'], $intent['date_to'], $lang);
        }
        if (is_string($intent['date_iso'])) {
            return ['from' => $intent['date_iso'], 'to' => $intent['date_iso']];
        }
        if (($intent['relative_expr'] ?? null) === 'terdekat' && $intent['intent'] === 'hari_baik') {
            $found = $this->findNextRecommended($intent, $today);
            if ($found !== null) {
                return ['from' => $found, 'to' => $found];
            }
        }
        $relative = $this->dates->resolve($intent['relative_expr'], $today);
        if ($relative !== null) {
            return $this->guardSpan($relative['from'], $relative['to'], $lang);
        }
        if ($intent['intent'] === 'hari_baik') {
            $found = $this->findNextRecommended($intent, $today);
            if ($found !== null) {
                return ['from' => $found, 'to' => $found];
            }
            $start = $today->modify('+1 day')->format('Y-m-d');
            $end = $today->modify('+14 day')->format('Y-m-d');

            return ['from' => $start, 'to' => $end];
        }
        if ($intent['intent'] === 'cek_upacara' || $intent['intent'] === 'info_tanggal') {
            throw new ApiProblem(
                'VALIDATION_ERROR',
                ApiResponse::message('VALIDATION_ERROR', $lang).' '.($lang === 'en'
                    ? 'Include a date or a phrase such as "next week".'
                    : 'Sertakan tanggal atau ungkapan seperti "minggu depan".'),
                422
            );
        }

        return ['from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function guardSpan(string $from, string $to, string $lang): array
    {
        if ($to < $from) {
            throw new ApiProblem('VALIDATION_ERROR', ApiResponse::message('VALIDATION_ERROR', $lang), 422);
        }
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $inclusive = (int) $start->diff($end)->days + 1;
        $max = (int) config('sanggah.max_range_days');
        if ($inclusive > $max) {
            throw new ApiProblem('RANGE_TOO_LARGE', ApiResponse::message('RANGE_TOO_LARGE', $lang), 422);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function findNextRecommended(array $intent, DateTimeImmutable $today): ?string
    {
        if (! is_string($intent['activity'] ?? null)) {
            return null;
        }
        $ruleRows = CalendarRule::query()->where('status', 'validated')->get()->map->toEngineArray()->all();
        for ($i = 1; $i <= 366; $i++) {
            $day = $today->modify('+'.$i.' day');
            $described = $this->engine->describe($day, SasihDay::payloadFor($day->format('Y-m-d')));
            $matched = $this->rules->match($described, $ruleRows, $intent['activity']);
            if ($this->recommended($matched, $intent)) {
                return $day->format('Y-m-d');
            }
        }

        return null;
    }

    private function findNext(?string $code, DateTimeImmutable $today, bool $forward): ?string
    {
        $limit = $code === null ? 62 : 400;
        $start = $forward ? 0 : 1;
        for ($i = $start; $i <= $limit; $i++) {
            $day = $today->modify(($forward ? '+' : '-').$i.' day');
            $iso = $day->format('Y-m-d');
            $names = $this->engine->describe($day, SasihDay::payloadFor($iso))['rerahinan'];
            if ($code === null) {
                if ($names !== []) {
                    return $iso;
                }
                continue;
            }
            foreach ($names as $name) {
                if ($name === $code || ($code === 'tumpek' && str_starts_with((string) $name, 'tumpek'))) {
                    return $iso;
                }
            }
        }

        return null;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function daysBetween(string $from, string $to): array
    {
        $zone = new DateTimeZone(CalendarEngine::TIMEZONE);
        $cursor = new DateTimeImmutable($from, $zone);
        $end = new DateTimeImmutable($to, $zone);
        $days = [];
        while ($cursor <= $end) {
            $days[] = $cursor;
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $matched
     * @param  array<string, mixed>  $intent
     */
    private function recommended(array $matched, array $intent): bool
    {
        if ($intent['intent'] !== 'hari_baik' || ! is_string($intent['activity'])) {
            return false;
        }
        $good = false;
        foreach ($matched as $rule) {
            if ($rule['effect'] === 'bad') {
                return false;
            }
            if ($rule['effect'] === 'good' && in_array($intent['activity'], $rule['activity_tags'], true)) {
                $good = true;
            }
        }

        return $good;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $days
     */
    private function template(array $intent, array $days, string $lang, string $disclaimer): string
    {
        $lines = [];
        $lines[] = $lang === 'en'
            ? 'Calendar engine (dates are computed, not guessed by the model):'
            : 'Mesin kalender (tanggal dihitung, bukan tebakan model bahasa):';
        if ($intent['intent'] === 'hari_baik' && ! is_string($intent['activity'])) {
            $lines[] = $lang === 'en'
                ? 'No activity was recognized. The validated subset currently covers opening a business (buka_usaha) and marriage (nikah). The days below are descriptive only.'
                : 'Kegiatan belum dikenali. Subset aturan yang tervalidasi saat ini mencakup buka usaha dan nikah. Hari di bawah ini hanya bersifat deskriptif.';
        }
        $recommendedDays = array_values(array_filter($days, fn (array $day) => ! empty($day['recommended'])));
        if ($intent['intent'] === 'hari_baik' && $recommendedDays !== []) {
            $first = $recommendedDays[0];
            $lines[] = $lang === 'en'
                ? 'Nearest day in the validated subset: '.$first['date'].' ('.$first['saptawara'].' '.$first['pancawara'].', wuku '.$first['wuku'].'). This is not a decision.'
                : 'Hari terdekat pada subset aturan yang tervalidasi: '.$first['date'].' ('.$first['saptawara'].' '.$first['pancawara'].', wuku '.$first['wuku'].'). Ini bukan keputusan.';
        }
        $anyRecommended = false;
        foreach ($days as $day) {
            $rerahinan = $day['rerahinan'] !== [] ? implode(', ', $day['rerahinan']) : ($lang === 'en' ? 'none' : 'tidak ada');
            $sasih = $day['sasih'] ? ' · sasih '.$day['sasih'].($day['penanggal_pangelong'] ? ' '.$day['penanggal_pangelong'] : '') : '';
            $lines[] = sprintf(
                '- %s: %s %s, wuku %s, pawukon day %d. %s: %s%s',
                $day['date'],
                $day['saptawara'],
                $day['pancawara'],
                $day['wuku'],
                $day['pawukon_day'],
                $lang === 'en' ? 'Holy days' : 'Rerahinan',
                $rerahinan,
                $sasih
            );
            foreach ($day['ceremonies'] as $ceremony) {
                $lines[] = $lang === 'en'
                    ? '  Ceremony alert: '.$ceremony['name'].' (/upacara/'.$ceremony['slug'].')'
                    : '  Ceremony alert: '.$ceremony['name'].' (/upacara/'.$ceremony['slug'].')';
            }
            foreach ($day['rules'] as $rule) {
                $lines[] = '  '.($lang === 'en' ? 'Rule' : 'Aturan').': '.$rule['name'].' ['.$rule['effect'].']';
            }
            if (! empty($day['recommended'])) {
                $anyRecommended = true;
                $lines[] = $lang === 'en'
                    ? '  Marked by a validated good-day rule for this activity, and no blocking rule fired.'
                    : '  Terpicu aturan hari baik yang tervalidasi untuk kegiatan ini, dan tidak ada aturan penghalang.';
            }
        }
        if ($intent['intent'] === 'hari_baik' && is_string($intent['activity']) && ! $anyRecommended) {
            $lines[] = $lang === 'en'
                ? 'No validated good-day rule matched this range. That is not a "bad day" verdict. Ask a pemangku or sulinggih.'
                : 'Tidak ada aturan hari baik yang tervalidasi pada rentang ini. Ini bukan vonis hari buruk. Tanyakan kepada pemangku atau sulinggih.';
        }
        $lines[] = $disclaimer;

        return implode("\n", $lines);
    }

    private function cleanDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
