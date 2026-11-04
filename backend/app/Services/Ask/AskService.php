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
use App\Services\Calendar\KalenderBaliClient;
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
        private readonly KalenderBaliClient $kalenderBali,
    ) {}

    /** @var array<string, list<array{date: string, name: string, url: string}>> */
    private array $remoteByDate = [];

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

        $days = $this->targetDays($intent, $today, $lang);
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
                ->with('sources')
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
                    'sources' => $ceremony->sources->map(fn ($source) => [
                        'title' => $source->title,
                        'publisher' => $source->publisher,
                        'url' => $source->url,
                    ])->all(),
                ])->all();
            $described['recommended'] = $this->recommended($matched, $intent);
            $described['rerahinan_labels'] = array_map(
                fn (string $code) => $this->rerahinanLabel($code, $lang),
                $described['rerahinan']
            );
            $described['kalenderbali'] = $this->remoteByDate[$iso] ?? [];
            $engineDays[] = $described;
        }

        $template = $this->template($intent, $engineDays, $lang, $today);
        $answer = $template;
        $mode = 'template';
        if ($this->ai->providerName() !== 'mock') {
            $prose = $this->explainWithModel((string) ($input['question'] ?? ''), $intent, $engineDays, $today, $lang);
            if ($prose !== null) {
                $answer = $prose;
                $mode = 'llm';
            }
        }
        $answer = trim(str_replace($disclaimer, '', $answer));

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
        if (empty($parsed['year']) && ! empty($keyword['year'])) {
            $parsed['year'] = $keyword['year'];
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $days
     */
    private function explainWithModel(string $question, array $intent, array $days, DateTimeImmutable $today, string $lang): ?string
    {
        $context = [
            'question' => $question,
            'today' => $today->format('Y-m-d'),
            'activity' => $intent['activity'] ?? null,
            'days' => array_map(fn (array $day) => [
                'date' => $day['date'],
                'date_label' => $this->humanDate((string) $day['date'], $lang),
                'wuku' => $day['wuku'],
                'saptawara' => $day['saptawara'],
                'pancawara' => $day['pancawara'],
                'sasih' => $day['sasih'],
                'penanggal_pangelong' => $day['penanggal_pangelong'],
                'rerahinan' => $day['rerahinan_labels'] ?? [],
                'recommended' => (bool) ($day['recommended'] ?? false),
                'rules' => array_map(fn (array $rule) => $rule['name'], $day['rules']),
                'ceremonies' => array_map(fn (array $ceremony) => $ceremony['name'], $day['ceremonies']),
            ], $days),
        ];
        $system = $this->prompts->get('calendar');
        $user = "CONTEXT:\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        try {
            $text = $this->guardedProse($system, $user, $days, $lang);
            if ($text !== null) {
                return $text;
            }
            $retry = $this->guardedProse(
                $system."\n\nThe previous reply was rejected because it added a date or a name that is not in CONTEXT. Rewrite the answer using only CONTEXT.",
                $user,
                $days,
                $lang
            );
            if ($retry !== null) {
                return $retry;
            }
        } catch (AiException | Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function guardedProse(string $system, string $user, array $days, string $lang): ?string
    {
        $text = $this->prose($system, $user);
        if ($text === null) {
            return null;
        }
        $text = $this->humanizeCodes($this->guard->stripUnknownDates($text, $days), $lang);

        return $this->guard->accepts($text, $days) ? $text : null;
    }

    private function prose(string $system, string $user): ?string
    {
        $response = $this->ai->generate($system, $user, ['title' => 'CalendarExplanation', 'format' => 'text']);
        $text = trim($response->text);
        if (is_array($response->json)) {
            $candidate = $response->json['answer'] ?? $response->json['text'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $text = trim($candidate);
            }
        }
        $text = preg_replace('/^```(?:\w+)?\s*|\s*```$/', '', $text) ?? $text;

        return trim($text) === '' ? null : trim($text);
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
                'metatah' => 'potong_gigi',
                'mapandes' => 'potong_gigi',
                'mesangih' => 'potong_gigi',
                'ngaben' => 'ngaben',
                'bertani' => 'menanam',
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
            'year' => $this->cleanYear($raw['year'] ?? null),
            'lang' => $lang,
        ];
    }

    private function cleanYear(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d{4}$/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 2020 || $value > 2035) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array{from: string, to: string}
     */
    /**
     * @param  array<string, mixed>  $intent
     * @return list<DateTimeImmutable>
     */
    private function targetDays(array $intent, DateTimeImmutable $today, string $lang): array
    {
        if ($this->searchesActivityWindow($intent)) {
            $dates = $this->activityDates($intent, $today);
            if ($dates !== []) {
                return array_map(fn (string $iso) => new DateTimeImmutable($iso), $dates);
            }
        }

        if ($intent['intent'] === 'rerahinan_berikutnya' && ! empty($intent['year'])) {
            $remote = $this->rememberRemote($intent['rerahinan'] ?? '', (int) $intent['year']);
            $found = $remote !== []
                ? array_values(array_unique(array_column($remote, 'date')))
                : $this->findInYear($intent['rerahinan'] ?? null, (int) $intent['year']);
            if ($found === []) {
                throw new ApiProblem(
                    'NOT_FOUND',
                    $lang === 'en'
                        ? 'That observance does not fall in '.$intent['year'].' on the pawukon cycle or the curated sasih table.'
                        : 'Hari itu tidak jatuh pada tahun '.$intent['year'].' menurut siklus pawukon atau tabel sasih terkurasi.',
                    404
                );
            }

            return array_map(fn (string $iso) => new DateTimeImmutable($iso), $found);
        }

        $span = $this->span($intent, $today, $lang);

        return $this->daysBetween($span['from'], $span['to']);
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<array{date: string, name: string, url: string}>
     */
    private function rememberRemote(string $code, int $year): array
    {
        if ($code === '') {
            return [];
        }
        try {
            $events = $this->kalenderBali->matching($code, $year);
        } catch (Throwable) {
            return [];
        }
        foreach ($events as $event) {
            $this->remoteByDate[$event['date']][] = $event;
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function findInYear(?string $code, int $year): array
    {
        $dates = [];
        $cursor = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $end = new DateTimeImmutable(sprintf('%04d-12-31', $year));
        while ($cursor <= $end) {
            $names = $this->engine->describe($cursor, SasihDay::payloadFor($cursor->format('Y-m-d')))['rerahinan'];
            foreach ($names as $name) {
                if ($code === null || $name === $code || ($code === 'tumpek' && str_starts_with((string) $name, 'tumpek'))) {
                    $dates[] = $cursor->format('Y-m-d');
                    break;
                }
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
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
            $notable = $this->findNotableDates($today, 90, 3);
            if ($notable !== []) {
                return ['from' => $notable[0], 'to' => $notable[array_key_last($notable)]];
            }
            $start = $today->modify('+1 day')->format('Y-m-d');

            return ['from' => $start, 'to' => $start];
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
        $found = $this->findRecommendedDates($intent, $today, 180, 1);

        return $found[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function searchesActivityWindow(array $intent): bool
    {
        if (($intent['intent'] ?? null) !== 'hari_baik' || ! is_string($intent['activity'] ?? null)) {
            return false;
        }
        if (is_string($intent['date_from'] ?? null) || is_string($intent['date_iso'] ?? null)) {
            return false;
        }
        $relative = $intent['relative_expr'] ?? null;

        return ! is_string($relative) || $relative === '' || $relative === 'terdekat';
    }

    /**
     * Up to three recommended days inside 180 days, or three notable days inside 90.
     *
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function activityDates(array $intent, DateTimeImmutable $today): array
    {
        $found = $this->findRecommendedDates($intent, $today, 180, 3);
        if ($found !== []) {
            return $found;
        }

        return $this->findNotableDates($today, 90, 3);
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function findRecommendedDates(array $intent, DateTimeImmutable $today, int $limit, int $max): array
    {
        if (! is_string($intent['activity'] ?? null) || $max < 1) {
            return [];
        }
        $ruleRows = CalendarRule::query()->where('status', 'validated')->get()->map->toEngineArray()->all();
        $seek = function (int $direction) use ($intent, $today, $ruleRows, $limit, $max): array {
            $found = [];
            for ($i = 1; $i <= $limit && count($found) < $max; $i++) {
                $day = $today->modify(($direction > 0 ? '+' : '-').$i.' day');
                $described = $this->engine->describe($day, SasihDay::payloadFor($day->format('Y-m-d')));
                if ($described['phase'] === null) {
                    continue;
                }
                $matched = $this->rules->match($described, $ruleRows, $intent['activity']);
                if ($this->recommended($matched, $intent)) {
                    $found[] = $day->format('Y-m-d');
                }
            }

            return $found;
        };
        $future = $seek(1);

        return $future !== [] ? $future : $seek(-1);
    }

    /**
     * @return list<string>
     */
    private function findNotableDates(DateTimeImmutable $today, int $limit, int $max): array
    {
        $found = [];
        for ($i = 1; $i <= $limit && count($found) < $max; $i++) {
            $day = $today->modify('+'.$i.' day');
            $described = $this->engine->describe($day, SasihDay::payloadFor($day->format('Y-m-d')));
            if ($this->isNotable($described)) {
                $found[] = $day->format('Y-m-d');
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function isNotable(array $day): bool
    {
        foreach ((array) ($day['rerahinan'] ?? []) as $name) {
            if (! is_string($name)) {
                continue;
            }
            if (in_array($name, ['tilem', 'purnama', 'galungan', 'kuningan', 'nyepi', 'saraswati', 'pagerwesi', 'kajeng_keliwon'], true)) {
                return true;
            }
            if (str_starts_with($name, 'tumpek')) {
                return true;
            }
        }

        return false;
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
    private function template(array $intent, array $days, string $lang, DateTimeImmutable $today): string
    {
        $recommended = array_values(array_filter($days, fn (array $day) => ! empty($day['recommended'])));
        $activity = $this->activityLabel(is_string($intent['activity'] ?? null) ? $intent['activity'] : null, $lang);
        $ask = $lang === 'en'
            ? 'This is not a decision. Please ask a pemangku or sulinggih.'
            : 'Ini bukan keputusan. Tanyakan juga ke pemangku atau sulinggih.';

        if ($intent['intent'] === 'hari_baik' && $recommended !== []) {
            $first = $recommended[0];
            $when = $this->humanDate((string) $first['date'], $lang).' ('.$first['date'].')';
            $place = $first['saptawara'].' '.$first['pancawara'].', wuku '.$first['wuku'];
            $rules = $this->ruleNames($first, $lang);
            $passed = $first['date'] < $today->format('Y-m-d');
            if ($lang === 'en') {
                $lead = $passed
                    ? "The matching day for {$activity} in the curated table is {$when}, {$place}. That date has already passed."
                    : "The nearest day for {$activity} is {$when}, {$place}.";
                if ($rules !== '') {
                    $lead .= " The published rule is {$rules}.";
                }

                return $lead.' '.$ask;
            }
            $lead = $passed
                ? "Di tabel yang ada, hari untuk {$activity} yang cocok adalah {$when}, {$place}. Tanggal itu sudah lewat."
                : "Hari terdekat untuk {$activity} adalah {$when}, {$place}.";
            if ($rules !== '') {
                $lead .= " Aturannya {$rules}.";
            }

            return $lead.' '.$ask;
        }

        if ($intent['intent'] === 'hari_baik') {
            $notes = $this->notablePhrases($days, $lang);
            if ($lang === 'en') {
                $lead = "In the range I checked, the validated subset does not mark a good day for {$activity}.";
                if ($notes !== '') {
                    $lead .= " Nearby days worth knowing: {$notes}.";
                }

                return $lead.' '.$ask;
            }
            $lead = "Di rentang yang saya cek, subset dewasa yang tervalidasi belum menandai hari baik untuk {$activity}.";
            if ($notes !== '') {
                $lead .= " Yang terdekat dan patut dicatat: {$notes}.";
            }

            return $lead.' '.$ask;
        }

        $notes = $this->notablePhrases($days, $lang);
        if ($notes === '') {
            $only = $days[0] ?? null;
            if (is_array($only)) {
                $when = $this->humanDate((string) $only['date'], $lang).' ('.$only['date'].')';
                $place = $only['saptawara'].' '.$only['pancawara'].', wuku '.$only['wuku'];

                return $lang === 'en'
                    ? "On {$when} it is {$place}. {$ask}"
                    : "Pada {$when} hari itu {$place}. {$ask}";
            }

            return $ask;
        }

        return $lang === 'en'
            ? "Here is what falls in that range: {$notes}. {$ask}"
            : "Yang jatuh pada rentang itu: {$notes}. {$ask}";
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function notablePhrases(array $days, string $lang): string
    {
        $phrases = [];
        foreach ($days as $day) {
            if (count($phrases) >= 3) {
                break;
            }
            $names = array_values(array_filter(
                (array) ($day['rerahinan_labels'] ?? []),
                fn ($name) => is_string($name) && $name !== ''
            ));
            if ($names === []) {
                foreach ((array) ($day['ceremonies'] ?? []) as $ceremony) {
                    if (is_array($ceremony) && is_string($ceremony['name'] ?? null)) {
                        $names[] = $ceremony['name'];
                    }
                }
            }
            if ($names === []) {
                continue;
            }
            $when = $this->humanDate((string) $day['date'], $lang);
            $phrases[] = $lang === 'en'
                ? implode(' and ', $names).' on '.$when
                : implode(' dan ', $names).' pada '.$when;
        }

        if ($phrases === []) {
            return '';
        }
        if (count($phrases) === 1) {
            return $phrases[0];
        }
        $last = array_pop($phrases);

        return implode(', ', $phrases).($lang === 'en' ? ', and ' : ', serta ').$last;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function ruleNames(array $day, string $lang): string
    {
        $names = [];
        foreach ((array) ($day['rules'] ?? []) as $rule) {
            if (! is_array($rule) || ($rule['effect'] ?? null) !== 'good') {
                continue;
            }
            $name = trim((string) preg_replace('/\s*\(.*$/', '', (string) ($rule['name'] ?? '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $names = array_slice(array_values(array_unique($names)), 0, 2);
        if (count($names) === 2) {
            return $names[0].($lang === 'en' ? ' and ' : ' dan ').$names[1];
        }

        return $names[0] ?? '';
    }

    private function activityLabel(?string $activity, string $lang): string
    {
        $labels = [
            'buka_usaha' => ['id' => 'buka usaha', 'en' => 'opening a business'],
            'pindah_rumah' => ['id' => 'pindah rumah', 'en' => 'moving house'],
            'membangun' => ['id' => 'membangun rumah', 'en' => 'building a house'],
            'nikah' => ['id' => 'menikah', 'en' => 'getting married'],
            'potong_gigi' => ['id' => 'potong gigi', 'en' => 'tooth filing'],
            'potong_rambut' => ['id' => 'potong rambut', 'en' => 'a hair cutting'],
            'ngaben' => ['id' => 'ngaben', 'en' => 'ngaben'],
            'menanam' => ['id' => 'menanam', 'en' => 'planting'],
        ];
        if ($activity === null || ! isset($labels[$activity])) {
            return $lang === 'en' ? 'that activity' : 'kegiatan itu';
        }

        return $labels[$activity][$lang === 'en' ? 'en' : 'id'];
    }

    private function humanDate(string $iso, string $lang): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false) {
            return $iso;
        }
        $months = $lang === 'en'
            ? [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December']
            : [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

        return (int) $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }

    private function rerahinanLabel(string $code, string $lang): string
    {
        $labels = [
            'kajeng_keliwon' => 'Kajeng Kliwon',
            'tumpek_landep' => 'Tumpek Landep',
            'tumpek_uduh' => 'Tumpek Uduh',
            'tumpek_krulut' => 'Tumpek Krulut',
            'tumpek_kandang' => 'Tumpek Kandang',
            'tumpek_wayang' => 'Tumpek Wayang',
            'buda_cemeng' => 'Buda Cemeng',
            'anggara_kasih' => 'Anggara Kasih',
            'galungan' => 'Galungan',
            'kuningan' => 'Kuningan',
            'pagerwesi' => 'Pagerwesi',
            'saraswati' => 'Saraswati',
            'purnama' => $lang === 'en' ? 'Purnama' : 'Purnama',
            'tilem' => 'Tilem',
            'nyepi' => 'Nyepi',
            'penyekeban_galungan' => 'Penyekeban Galungan',
            'penyajaan_galungan' => 'Penyajaan Galungan',
            'penampahan_galungan' => 'Penampahan Galungan',
            'umanis_galungan' => 'Umanis Galungan',
            'penampahan_kuningan' => 'Penampahan Kuningan',
            'ngembak_geni' => 'Ngembak Geni',
            'pengerupukan' => 'Pengerupukan',
            'siwa_ratri' => 'Siwa Ratri',
        ];

        return $labels[$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    private function humanizeCodes(string $answer, string $lang): string
    {
        $codes = [
            'kajeng_keliwon', 'tumpek_landep', 'tumpek_uduh', 'tumpek_krulut', 'tumpek_kandang',
            'tumpek_wayang', 'buda_cemeng', 'anggara_kasih', 'penyekeban_galungan', 'penyajaan_galungan',
            'penampahan_galungan', 'umanis_galungan', 'penampahan_kuningan', 'ngembak_geni',
            'pengerupukan', 'siwa_ratri', 'kajeng keliwon',
        ];
        foreach ($codes as $code) {
            $answer = str_ireplace($code, $this->rerahinanLabel(str_replace(' ', '_', $code), $lang), $answer);
        }

        return $answer;
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
