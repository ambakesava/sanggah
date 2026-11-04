<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke()
    {
        $db = 'ok';
        try {
            DB::select('select 1');
        } catch (Throwable) {
            $db = 'error';
        }
        $provider = config('sanggah.ai.provider');
        $gemini = (string) config('sanggah.ai.gemini.key') !== '';
        $qwen = (string) config('sanggah.ai.qwen.key') !== '';
        $active = ($provider === 'mock' || (! $gemini && ! $qwen)) ? 'mock' : $provider;

        return ApiResponse::ok([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'db' => $db,
            'cache' => config('cache.default'),
            'retrieval' => 'lexical',
            'vector_store' => 'not_configured',
            'ai' => [
                'provider' => $active,
                'gemini_configured' => $gemini,
                'qwen_configured' => $qwen,
                'text_model' => $active === 'qwen' ? config('sanggah.ai.qwen.text_model') : config('sanggah.ai.gemini.text_model'),
                'vision_model' => $active === 'qwen' ? config('sanggah.ai.qwen.vision_model') : config('sanggah.ai.gemini.vision_model'),
            ],
        ]);
    }
}
