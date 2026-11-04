<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CulturalObject;
use App\Services\Explain\ExplainService;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;

class ExplainController extends Controller
{
    public function __construct(private readonly ExplainService $explain) {}

    public function store(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $data = $request->validate([
            'object_slug' => ['required', 'string'],
            'question' => ['nullable', 'string', 'max:500'],
        ]);
        $object = CulturalObject::query()->where('slug', $data['object_slug'])->where('is_active', true)->first();
        if ($object === null) {
            return ApiResponse::error('NOT_FOUND', ApiResponse::message('NOT_FOUND', $lang), 404);
        }

        return ApiResponse::ok($this->explain->explain($object, $lang, $data['question'] ?? null));
    }
}
