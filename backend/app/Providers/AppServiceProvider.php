<?php

namespace App\Providers;

use App\Services\Ai\AiService;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\MockProvider;
use App\Services\Ai\OfflineIntentParser;
use App\Services\Ai\PromptLibrary;
use App\Services\Ai\QwenProvider;
use App\Services\Ai\ResilientAiService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiService::class, function () {
            $prompts = new PromptLibrary;
            $mock = new MockProvider(new OfflineIntentParser);
            $timeout = (int) config('sanggah.ai.timeout');
            $retry = (int) config('sanggah.ai.max_retry');
            $geminiKey = (string) config('sanggah.ai.gemini.key');
            $qwenKey = (string) config('sanggah.ai.qwen.key');
            $gemini = $geminiKey !== '' ? new GeminiProvider(
                $geminiKey,
                (string) config('sanggah.ai.gemini.base'),
                (string) config('sanggah.ai.gemini.text_model'),
                (string) config('sanggah.ai.gemini.vision_model'),
                $timeout,
                $retry,
                $prompts,
            ) : null;
            $qwen = $qwenKey !== '' ? new QwenProvider(
                $qwenKey,
                (string) config('sanggah.ai.qwen.base'),
                (string) config('sanggah.ai.qwen.text_model'),
                (string) config('sanggah.ai.qwen.vision_model'),
                $timeout,
                $retry,
                $prompts,
            ) : null;

            $choice = (string) config('sanggah.ai.provider');
            $primary = $mock;
            $secondary = null;
            if ($choice !== 'mock' && ($gemini || $qwen)) {
                if ($choice === 'qwen') {
                    $primary = $qwen ?? $gemini ?? $mock;
                    $secondary = $primary === $qwen ? $gemini : null;
                } else {
                    $primary = $gemini ?? $qwen ?? $mock;
                    $secondary = $primary === $gemini ? $qwen : null;
                }
            }

            return new ResilientAiService(
                $primary,
                $secondary,
                (bool) config('sanggah.ai.force_fail'),
                (int) config('sanggah.ai.daily_budget'),
            );
        });
    }

    public function boot(): void
    {
        $this->absolutizeSqlitePath();

        RateLimiter::for('scan', function (Request $request) {
            $key = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute((int) config('sanggah.rate.scan_per_minute'))->by('scan|'.$key),
                Limit::perDay((int) config('sanggah.rate.scan_per_day'))->by('scan-day|'.$key),
            ];
        });
        RateLimiter::for('ask', function (Request $request) {
            return Limit::perMinute((int) config('sanggah.rate.ask_per_minute'))->by('ask|'.($request->ip() ?: 'unknown'));
        });
        RateLimiter::for('feedback', function (Request $request) {
            return Limit::perHour((int) config('sanggah.rate.feedback_per_hour'))->by('feedback|'.($request->ip() ?: 'unknown'));
        });
        RateLimiter::for('admin', function (Request $request) {
            $id = $request->user()?->id ?: $request->ip();

            return Limit::perMinute((int) config('sanggah.rate.admin_per_minute'))->by('admin|'.$id);
        });
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute((int) config('sanggah.rate.login_per_minute'))->by('login|'.($request->ip() ?: 'unknown'));
        });
    }

    private function absolutizeSqlitePath(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }
        $path = config('database.connections.sqlite.database');
        if (! is_string($path) || $path === '' || $path === ':memory:') {
            return;
        }
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR)
            || (strlen($path) > 2 && ctype_alpha($path[0]) && ($path[1] === ':'));
        if ($absolute) {
            return;
        }
        config(['database.connections.sqlite.database' => base_path($path)]);
        DB::purge('sqlite');
    }
}
