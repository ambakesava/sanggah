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
        $history = $this->historyFrom($input);
        $question = (string) ($input['question'] ?? '');
        $lang = $this->detectLang($question, $history, $lang);
        $intent = $this->applyConversation($this->intent($input, $lang, $today), $question, $history, $today);
        $intent['greet'] = ! $this->historyHasAssistant($history);
        $disclaimer = (string) config('sanggah.disclaimer.calendar.'.$lang);

        if (($intent['intent'] ?? null) === 'explain_rule') {
            $answer = $this->finishAnswer($this->ruleAnswer($intent, $lang), $intent, $lang, false);

            return $this->envelope($intent, null, $answer, 'template', false, $disclaimer, $this->followups($intent, [], $lang), $lang);
        }

        if ($intent['intent'] === 'hari_baik' && ! is_string($intent['activity'] ?? null)) {
            $answer = $this->finishAnswer($this->clarifyAnswer($lang), $intent, $lang, false);

            return $this->envelope($intent, null, $answer, 'template', false, $disclaimer, $this->followups($intent, [], $lang), $lang);
        }

        if ($intent['intent'] === 'out_of_scope') {
            $answer = $this->finishAnswer(
                $lang === 'en'
                    ? 'I can help with the Balinese calendar and choosing a day for a ceremony. Try asking when a good day for a wedding is.'
                    : 'Aku bantu soal kalender Bali dan hari baik upacara. Coba tanya, misalnya, kapan hari baik untuk nikah.',
                $intent,
                $lang,
                false
            );

            return $this->envelope($intent, null, $answer, 'template', false, $disclaimer, [], $lang);
        }

        if ($intent['intent'] === 'needs_form') {
            $answer = $this->finishAnswer($this->clarifyAnswer($lang), $intent, $lang, false);

            return $this->envelope($intent, null, $answer, 'template', true, $disclaimer, $this->followups($intent, [], $lang), $lang);
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
            $prose = $this->explainWithModel($question, $intent, $engineDays, $today, $lang, $history);
            if ($prose !== null) {
                $answer = $prose;
                $mode = 'llm';
            }
        }
        $answer = $this->finishAnswer(trim(str_replace($disclaimer, '', $answer)), $intent, $lang, true);

        return $this->envelope($intent, $engineDays, $answer, $mode, false, $disclaimer, $this->followups($intent, $engineDays, $lang), $lang);
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
        if ($keyword['intent'] === 'hari_baik') {
            $parsed['intent'] = 'hari_baik';
            if (is_string($keyword['activity'])) {
                $parsed['activity'] = $keyword['activity'];
            }
            if ($keyword['date_iso'] === null) {
                $parsed['date_iso'] = null;
            }
            if ($keyword['date_from'] === null) {
                $parsed['date_from'] = null;
                $parsed['date_to'] = null;
            }
            $parsed['relative_expr'] = $this->knownRelative($keyword['relative_expr'] ?? null)
                ?? $this->knownRelative($parsed['relative_expr'] ?? null);
        } elseif (in_array($parsed['intent'], ['out_of_scope', 'needs_form'], true) && ! in_array($keyword['intent'], ['out_of_scope', 'needs_form'], true)) {
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

    private function knownRelative(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = mb_strtolower(trim($value));
        $known = ['terdekat', 'minggu depan', 'next week', 'minggu ini', 'this week', 'bulan depan', 'next month', 'besok', 'tomorrow', 'lusa', 'hari ini', 'today'];

        return in_array($value, $known, true) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $days
     */
    private function explainWithModel(string $question, array $intent, array $days, DateTimeImmutable $today, string $lang, array $history): ?string
    {
        $context = [
            'question' => $question,
            'reply_lang' => $lang,
            'greet' => (bool) ($intent['greet'] ?? false),
            'history' => $history,
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
        if ($this->soundsRobotic($text)) {
            return null;
        }

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
    private function targetDays(array &$intent, DateTimeImmutable $today, string $lang): array
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
    private function activityDates(array &$intent, DateTimeImmutable $today): array
    {
        $found = $this->findRecommendedDates($intent, $today, 180, 3);
        if ($found !== []) {
            return $found;
        }
        $excluded = array_values(array_filter((array) ($intent['exclude_dates'] ?? []), is_string(...)));
        if (($intent['follow_up'] ?? null) === 'more' && $excluded !== []) {
            $intent['only_option'] = true;

            return array_slice($excluded, 0, 1);
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
        $skip = array_fill_keys(array_filter((array) ($intent['exclude_dates'] ?? []), is_string(...)), true);
        $seek = function (int $direction) use ($intent, $today, $ruleRows, $limit, $max, $skip): array {
            $found = [];
            for ($i = 1; $i <= $limit && count($found) < $max; $i++) {
                $day = $today->modify(($direction > 0 ? '+' : '-').$i.' day');
                $iso = $day->format('Y-m-d');
                if (isset($skip[$iso])) {
                    continue;
                }
                $described = $this->engine->describe($day, SasihDay::payloadFor($iso));
                if ($described['phase'] === null) {
                    continue;
                }
                $matched = $this->rules->match($described, $ruleRows, $intent['activity']);
                if ($this->recommended($matched, $intent)) {
                    $found[] = $iso;
                }
            }

            return $found;
        };
        $future = $seek(1);
        if ($future !== []) {
            return $future;
        }
        if (($intent['follow_up'] ?? null) === 'more') {
            return [];
        }

        return $seek(-1);
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
        $prefix = $this->greetPrefix($intent);

        if ($intent['intent'] === 'hari_baik' && $recommended !== [] && ($intent['follow_up'] ?? null) === 'why') {
            $day = $recommended[0];

            return $prefix.$this->whySentence($day, $activity, $lang);
        }

        if ($intent['intent'] === 'hari_baik' && ! empty($intent['only_option']) && $recommended !== []) {
            $day = $recommended[0];
            $when = $this->dayPhrase($day, $lang);
            if ($lang === 'en') {
                return $prefix."For {$activity}, the closest day is still {$when}. I don't see another one in the next few months. Which month are you planning? I'll look for one that fits.";
            }

            return $prefix."Untuk {$activity}, yang paling dekat tetap {$when}. Beberapa bulan ke depan belum ada hari lain yang pas. Rencananya bulan apa? Biar aku cariin yang pas.";
        }

        if ($intent['intent'] === 'hari_baik' && $recommended !== [] && ($intent['follow_up'] ?? null) === 'more') {
            $bits = array_map(fn (array $day) => $this->dayPhrase($day, $lang), array_slice($recommended, 0, 2));
            if ($lang === 'en') {
                return $prefix.'There is another one: '.implode(', and ', $bits).'.';
            }

            return $prefix.'Ada. Berikutnya '.implode(', dan ', $bits).'.';
        }

        if ($intent['intent'] === 'hari_baik' && $recommended !== []) {
            $first = $recommended[0];
            $when = $this->dayPhrase($first, $lang);
            $rules = $this->ruleNames($first, $lang);
            $passed = $first['date'] < $today->format('Y-m-d');
            if ($lang === 'en') {
                $lead = $passed
                    ? "For {$activity}, a fitting day was {$when}. That day has already passed."
                    : "For {$activity}, the nearest fitting day is {$when}.";
                if ($rules !== '') {
                    $lead .= " It falls under {$rules}, wuku {$first['wuku']}.";
                }

                return $prefix.$lead;
            }
            $lead = $passed
                ? "Untuk {$activity}, hari yang cocok jatuh pada {$when}. Hari itu sudah lewat."
                : "Untuk {$activity}, hari yang cocok paling dekat itu {$when}.";
            if ($rules !== '') {
                $lead .= " Wukunya {$first['wuku']}, masuk {$rules}.";
            }

            return $prefix.$lead;
        }

        if ($intent['intent'] === 'hari_baik') {
            $notes = $this->notablePhrases($days, $lang);
            if ($lang === 'en') {
                $lead = "I don't see a fitting day for {$activity} just there.";
                if ($notes !== '') {
                    $lead .= " Close by there is {$notes}.";
                }
                $lead .= ' Which month are you planning?';

                return $prefix.$lead;
            }
            $lead = "Untuk {$activity}, di situ belum ada hari yang pas.";
            if ($notes !== '') {
                $lead .= " Yang ada di dekatnya: {$notes}.";
            }
            $lead .= ' Rencananya bulan apa? Biar aku cariin yang pas.';

            return $prefix.$lead;
        }

        $notes = $this->notablePhrases($days, $lang);
        if ($notes === '') {
            $only = $days[0] ?? null;
            if (is_array($only)) {
                $when = $this->humanDate((string) $only['date'], $lang);

                return $prefix.($lang === 'en'
                    ? "On {$when} it is {$only['saptawara']} {$only['pancawara']}, wuku {$only['wuku']}."
                    : "Pada {$when} hari itu {$only['saptawara']} {$only['pancawara']}, wuku {$only['wuku']}.");
            }

            return $prefix.($lang === 'en' ? 'Tell me the day you have in mind.' : 'Sebut tanggalnya, ya, biar aku lihatkan.');
        }

        return $prefix.($lang === 'en' ? "It falls on {$notes}." : "Jatuhnya {$notes}.");
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

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{role: string, content: string}>
     */
    private function historyFrom(array $input): array
    {
        $rows = [];
        foreach (array_slice((array) ($input['history'] ?? []), -6) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $rows[] = [
                'role' => ($row['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr($content, 0, 2000),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function historyHasAssistant(array $history): bool
    {
        foreach ($history as $row) {
            if ($row['role'] === 'assistant') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function detectLang(string $question, array $history, string $fallback): string
    {
        $score = $this->langScore($question);
        if ($score['id'] === 0 && $score['en'] === 0) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                if ($history[$i]['role'] !== 'user') {
                    continue;
                }
                $score = $this->langScore($history[$i]['content']);
                if ($score['id'] !== 0 || $score['en'] !== 0) {
                    break;
                }
            }
        }
        if ($score['id'] === 0 && $score['en'] === 0) {
            return $fallback === 'en' ? 'en' : 'id';
        }

        return $score['id'] >= $score['en'] ? 'id' : 'en';
    }

    /**
     * @return array{id: int, en: int}
     */
    private function langScore(string $text): array
    {
        $lower = mb_strtolower($text);
        $id = 0;
        $en = 0;
        foreach (['kapan', 'untuk', 'hari', 'baik', 'ngaben', 'nikah', 'gigi', 'kalau', 'ada', 'kenapa', 'pilihan', 'dekat', 'terdekat', 'minggu', 'bulan', 'yang', 'apa', 'bagaimana', 'mau', 'saya', 'aku', 'matur', 'semeton', 'rumah', 'usaha'] as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $lower) === 1) {
                $id++;
            }
        }
        foreach (['when', 'what', 'good', 'day', 'why', 'other', 'next', 'please', 'wedding', 'marriage', 'where', 'how', 'week', 'month', 'ceremony'] as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $lower) === 1) {
                $en++;
            }
        }

        return ['id' => $id, 'en' => $en];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    private function applyConversation(array $intent, string $question, array $history, DateTimeImmutable $today): array
    {
        $lower = mb_strtolower(trim($question));
        $intent['follow_up'] = null;
        $intent['exclude_dates'] = [];
        $intent['rule_query'] = null;
        $intent['only_option'] = false;

        if ($this->containsFollow($lower, ['ada pilihan lain', 'pilihan lain', 'ada lagi', 'yang lain', 'other option', 'another day', 'any other'])) {
            $intent['intent'] = 'hari_baik';
            $intent['follow_up'] = 'more';
            $intent['activity'] = is_string($intent['activity'] ?? null) ? $intent['activity'] : $this->activityFromHistory($history);
            $intent['exclude_dates'] = $this->datesFromHistory($history);
            $intent['relative_expr'] = 'terdekat';
            $intent['date_iso'] = null;
            $intent['date_from'] = null;
            $intent['date_to'] = null;

            return $intent;
        }

        if ($this->containsFollow($lower, ['kenapa tanggal', 'kenapa hari', 'kenapa itu', 'why that', 'why is that', 'why this'])) {
            $intent['intent'] = 'hari_baik';
            $intent['follow_up'] = 'why';
            $intent['activity'] = is_string($intent['activity'] ?? null) ? $intent['activity'] : $this->activityFromHistory($history);
            $dates = $this->datesFromHistory($history);
            if ($dates !== []) {
                $intent['date_iso'] = $dates[0];
                $intent['date_from'] = null;
                $intent['date_to'] = null;
                $intent['relative_expr'] = null;
            }

            return $intent;
        }

        if (preg_match('/^(?:apa itu|what is|what\'s)\s+(.+)$/u', $lower, $match) === 1) {
            $query = trim($match[1], " ?.!");
            if (str_starts_with($lower, 'apa itu') || $this->ruleNote($query, 'id') !== null) {
                $intent['intent'] = 'explain_rule';
                $intent['follow_up'] = 'rule';
                $intent['rule_query'] = $query;

                return $intent;
            }
        }

        $month = $this->monthIn($lower);
        if ($month !== null) {
            $activity = is_string($intent['activity'] ?? null) ? $intent['activity'] : $this->activityFromHistory($history);
            if (is_string($activity)) {
                $intent['intent'] = 'hari_baik';
                $intent['activity'] = $activity;
                $year = (int) $today->format('Y');
                if ($month < (int) $today->format('n')) {
                    $year++;
                }
                $intent['date_from'] = sprintf('%04d-%02d-01', $year, $month);
                $intent['date_to'] = (new DateTimeImmutable($intent['date_from']))->modify('last day of this month')->format('Y-m-d');
                $intent['date_iso'] = null;
                $intent['relative_expr'] = null;
            }
        }

        if (! is_string($intent['activity'] ?? null)) {
            $inherited = $this->activityFromHistory($history);
            if (is_string($inherited) && in_array($intent['intent'], ['hari_baik', 'out_of_scope', 'needs_form'], true)) {
                $intent['activity'] = $inherited;
                if ($intent['intent'] !== 'hari_baik') {
                    $intent['intent'] = 'hari_baik';
                }
            }
        }

        return $intent;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsFollow(string $lower, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    private function activityFromHistory(array $history): ?string
    {
        $parser = new OfflineIntentParser;
        $activity = null;
        foreach ($history as $row) {
            if ($row['role'] !== 'user') {
                continue;
            }
            $parsed = $parser->parse($row['content'], 'id');
            if (is_string($parsed['activity'] ?? null)) {
                $activity = $parsed['activity'];
            }
        }

        return $activity;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<string>
     */
    private function datesFromHistory(array $history): array
    {
        $dates = [];
        foreach ($history as $row) {
            if ($row['role'] !== 'assistant') {
                continue;
            }
            foreach ($this->guard->datesIn($row['content']) as $iso) {
                $dates[] = $iso;
            }
        }

        return array_values(array_unique($dates));
    }

    private function monthIn(string $lower): ?int
    {
        $months = [
            'january' => 1, 'januari' => 1, 'february' => 2, 'februari' => 2, 'march' => 3, 'maret' => 3,
            'april' => 4, 'may' => 5, 'mei' => 5, 'june' => 6, 'juni' => 6, 'july' => 7, 'juli' => 7,
            'august' => 8, 'agustus' => 8, 'september' => 9, 'october' => 10, 'oktober' => 10,
            'november' => 11, 'december' => 12, 'desember' => 12,
        ];
        foreach ($months as $name => $number) {
            if (preg_match('/\b'.preg_quote($name, '/').'\b/u', $lower) === 1) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function greetPrefix(array $intent): string
    {
        return ! empty($intent['greet']) ? 'Om Swastiastu. ' : '';
    }

    private function closing(string $lang): string
    {
        return $lang === 'en'
            ? 'It is still best to ask a pemangku or sulinggih in your village first.'
            : 'Tetap baiknya matur dulu ke pemangku/sulinggih di desa ya.';
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function finishAnswer(string $answer, array $intent, string $lang, bool $withClosing): string
    {
        $answer = trim($answer);
        if (! empty($intent['greet']) && ! str_contains($answer, 'Om Swastiastu')) {
            $answer = 'Om Swastiastu. '.$answer;
        }
        if ($withClosing && ! str_contains(mb_strtolower($answer), 'pemangku')) {
            $answer = rtrim($answer, " \t.").'. '.$this->closing($lang);
        }

        return trim($answer);
    }

    private function clarifyAnswer(string $lang): string
    {
        return $lang === 'en'
            ? 'What is the plan for? A wedding, ngaben, tooth filing, or opening a shop? Then I can look for a day that fits.'
            : 'Rencananya untuk apa, semeton? Nikah, ngaben, potong gigi, atau buka usaha? Biar aku cariin hari yang pas.';
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function ruleAnswer(array $intent, string $lang): string
    {
        $query = mb_strtolower(trim((string) ($intent['rule_query'] ?? '')));
        $query = preg_replace('/\?+$/', '', $query) ?? $query;
        $note = $this->ruleNote($query, $lang);
        if ($note !== null) {
            return $note;
        }

        return $lang === 'en'
            ? 'I do not have a short note for that name yet. Ask me which ceremony you are planning.'
            : 'Aku belum punya catatan singkat untuk nama itu. Mau tanya hari baik untuk upacara yang mana?';
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function dayPhrase(array $day, string $lang): string
    {
        $when = $this->weekday((string) $day['saptawara'], $lang).', '.$this->humanDate((string) $day['date'], $lang);
        $holy = $this->holyPhrase((array) ($day['rerahinan_labels'] ?? []), $lang);

        return $holy !== '' ? $when.' - '.$holy : $when;
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function whySentence(array $day, string $activity, string $lang): string
    {
        $when = $this->humanDate((string) $day['date'], $lang);
        $holy = $this->holyPhrase((array) ($day['rerahinan_labels'] ?? []), $lang);
        $rules = $this->ruleNames($day, $lang);
        $because = $holy !== '' ? $holy : ($day['saptawara'].' '.$day['pancawara']);
        if ($lang === 'en') {
            $line = "That day, {$when}, fits because it is {$because}, wuku {$day['wuku']}.";
            if ($rules !== '') {
                $line .= " It falls under {$rules}.";
            }

            return $line;
        }
        $line = "Tanggal {$when} cocok karena {$because}, wuku {$day['wuku']}.";
        if ($rules !== '') {
            $line .= " Itu hari {$rules}, yang dipakai untuk {$activity}.";
        }

        return $line;
    }

    private function ruleNote(string $query, string $lang): ?string
    {
        $notes = [
            'amerta akasa' => ['Hari baik untuk memuja leluhur dan pitra yadnya, termasuk ngaben, saat Anggara jatuh pada Purnama.', 'A good day for ancestor offerings, including ngaben, when Anggara falls on the full moon.'],
            'amerta buwana' => ['Hari baik untuk dewa yadnya dan pitra yadnya, pada Redite, Soma, atau Anggara saat Purnama.', 'A good day for dewa yadnya and pitra yadnya, on Redite, Soma, or Anggara at the full moon.'],
            'amerta bumi' => ['Hari baik untuk perkawinan, pada Soma Wage penanggal 1 atau Buda Pon penanggal 10.', 'A good day for a wedding, on Soma Wage penanggal 1 or Buda Pon penanggal 10.'],
            'kamajaya' => ['Hari baik untuk pernikahan, saat Buda pada penanggal 2, 3, atau 7.', 'A good day for a wedding, when Buda falls on penanggal 2, 3, or 7.'],
            'derman bagia' => ['Hari baik untuk nikah dan mulai belajar, saat Soma pada penanggal 2, 3, 5, atau 12.', 'A good day for a wedding and for starting lessons, when Soma falls on penanggal 2, 3, 5, or 12.'],
            'ayu nulus' => ['Hari baik untuk memulai usaha, pada beberapa pasangan saptawara dan penanggal.', 'A good day for starting work, on certain saptawara and penanggal pairs.'],
            'amerta murti' => ['Hari baik untuk potong gigi, saat Buda Keliwon penanggal 12.', 'A good day for tooth filing, when Buda Keliwon falls on penanggal 12.'],
            'dirgayusa' => ['Hari baik untuk potong gigi dan manusa yadnya, saat Buda Pon penanggal 10.', 'A good day for tooth filing, when Buda Pon falls on penanggal 10.'],
        ];
        foreach ($notes as $name => $pair) {
            if (str_contains($query, $name)) {
                $label = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

                return $lang === 'en' ? $label.' is '.$pair[1] : $label.' itu '.$pair[0];
            }
        }

        return null;
    }

    private function weekday(string $saptawara, string $lang): string
    {
        $map = [
            'Redite' => ['Minggu', 'Sunday'],
            'Soma' => ['Senin', 'Monday'],
            'Anggara' => ['Selasa', 'Tuesday'],
            'Buda' => ['Rabu', 'Wednesday'],
            'Wraspati' => ['Kamis', 'Thursday'],
            'Sukra' => ['Jumat', 'Friday'],
            'Saniscara' => ['Sabtu', 'Saturday'],
        ];
        $pair = $map[$saptawara] ?? [$saptawara, $saptawara];

        return $lang === 'en' ? $pair[1] : $pair[0];
    }

    /**
     * @param  list<string>  $labels
     */
    private function holyPhrase(array $labels, string $lang): string
    {
        $labels = array_values(array_filter($labels, fn ($label) => is_string($label) && $label !== ''));
        $moon = null;
        $rest = [];
        foreach ($labels as $label) {
            if (in_array($label, ['Purnama', 'Tilem'], true)) {
                $moon = $label;
            } else {
                $rest[] = $label;
            }
        }
        usort($rest, function (string $a, string $b): int {
            $rank = fn (string $name): int => match (true) {
                str_contains($name, 'Kasih') => 0,
                str_starts_with($name, 'Tumpek') => 1,
                str_contains($name, 'Galungan'), str_contains($name, 'Kuningan'), $name === 'Nyepi' => 2,
                $name === 'Kajeng Kliwon' => 4,
                default => 3,
            };

            return $rank($a) <=> $rank($b);
        });
        if ($moon !== null && $rest !== []) {
            return $lang === 'en' ? $rest[0].' on '.$moon : $rest[0].' pas '.$moon;
        }

        return $moon ?? ($rest[0] ?? '');
    }

    private function soundsRobotic(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach ([
            'validated subset',
            'in the range i checked',
            'this is not a decision',
            'calendar engine',
            'mesin kalender',
            'pawukon day',
            'subset dewasa',
            'rentang yang saya cek',
            'nearby days worth',
            'the nearest day',
        ] as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $intent
     * @param  list<array<string, mixed>>|null  $days
     * @param  list<string>  $followups
     * @return array<string, mixed>
     */
    private function envelope(?array $intent, ?array $days, string $answer, string $mode, bool $needsForm, string $disclaimer, array $followups, string $lang): array
    {
        return [
            'intent' => $intent,
            'engine' => $days === null ? null : [
                'days' => $days,
                'rules_version' => (string) config('sanggah.rules_version'),
            ],
            'answer' => $answer,
            'answer_mode' => $mode,
            'needs_form' => $needsForm,
            'disclaimer' => $disclaimer,
            'followups' => $followups,
            'reply_lang' => $lang,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $days
     * @return list<string>
     */
    private function followups(array $intent, array $days, string $lang): array
    {
        $rule = '';
        foreach ($days as $day) {
            if (! empty($day['recommended'])) {
                $rule = explode($lang === 'en' ? ' and ' : ' dan ', $this->ruleNames($day, $lang))[0];
                break;
            }
        }
        if (($intent['follow_up'] ?? null) === 'rule') {
            return $lang === 'en'
                ? ['When is a good day for it?', 'Any other day?']
                : ['Kapan hari baiknya?', 'Ada pilihan lain?'];
        }
        if ($lang === 'en') {
            $chips = ['Why is that a good day?', 'Any other day?'];
            if ($rule !== '') {
                $chips[] = 'What is '.$rule.'?';
            }

            return $chips;
        }
        $chips = ['Kenapa tanggal itu baik?', 'Ada pilihan lain?'];
        if ($rule !== '') {
            $chips[] = 'Apa itu '.$rule.'?';
        }

        return $chips;
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
