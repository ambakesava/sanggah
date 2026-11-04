<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ask\AskService;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;

class AskController extends Controller
{
    public function __construct(private readonly AskService $ask) {}

    public function store(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $data = $request->validate([
            'question' => ['required_without:intent_override', 'nullable', 'string', 'max:500'],
            'history' => ['sometimes', 'array', 'max:8'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
            'intent_override' => ['sometimes', 'array'],
            'intent_override.intent' => ['sometimes', 'string'],
            'intent_override.activity' => ['nullable', 'string'],
            'intent_override.date_iso' => ['nullable', 'date'],
            'intent_override.date_from' => ['nullable', 'date'],
            'intent_override.date_to' => ['nullable', 'date'],
            'intent_override.relative_expr' => ['nullable', 'string'],
            'intent_override.rerahinan' => ['nullable', 'string'],
        ]);

        return ApiResponse::ok($this->ask->ask($data, $lang));
    }
}
