<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarRule;
use App\Models\SasihDay;
use App\Services\Calendar\CalendarEngine;
use App\Services\Calendar\RuleEvaluator;
use App\Support\ApiResponse;
use App\Support\Locale;
use DateTimeImmutable;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarEngine $engine,
        private readonly RuleEvaluator $rules,
    ) {}

    public function show(Request $request, string $date)
    {
        $lang = Locale::fromRequest($request);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return ApiResponse::error('VALIDATION_ERROR', ApiResponse::message('VALIDATION_ERROR', $lang), 422);
        }
        $day = $this->engine->describe($parsed, SasihDay::payloadFor($date));
        $matched = $this->rules->match(
            $day,
            CalendarRule::query()->where('status', 'validated')->get()->map->toEngineArray()->all(),
            null
        );
        $day['rules'] = array_map(fn (array $rule) => [
            'code' => $rule['code'],
            'name' => $lang === 'en' ? $rule['name_en'] : $rule['name_id'],
            'effect' => $rule['effect'],
            'source_id' => $rule['source_id'],
        ], $matched);

        return ApiResponse::ok([
            'data' => $day,
            'rules_version' => config('sanggah.rules_version'),
            'disclaimer' => config('sanggah.disclaimer.calendar.'.$lang),
        ]);
    }
}
