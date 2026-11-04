<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiException;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Primary provider, one automatic fallback, daily budget, and response cache.
 * Mock is used only when it is the selected primary (no API key).
 */
final class ResilientAiService implements AiService
{
    public function __construct(
        private readonly AiService $primary,
        private readonly ?AiService $secondary,
        private readonly bool $forceFail,
        private readonly int $dailyBudget,
    ) {}

    public function providerName(): string
    {
        return $this->primary->providerName();
    }

    public function identify(ImageInput $image, string $lang, array $classes): ScanResult
    {
        $this->guard();
        $filename = $this->primary->providerName() === 'mock' ? (string) $image->filename : '';
        $cacheKey = 'ai:scan:'.hash('sha256', $image->bytes.'|'.$lang.'|'.$this->primary->providerName().'|'.$filename.'|'.json_encode($classes));

        return Cache::remember($cacheKey, (int) config('sanggah.cache_ttl.scan'), function () use ($image, $lang, $classes) {
            return $this->attempt(fn (AiService $ai) => $ai->identify($image, $lang, $classes));
        });
    }

    public function embed(array $texts): array
    {
        $this->guard();

        return $this->attempt(fn (AiService $ai) => $ai->embed($texts));
    }

    public function generate(string $system, string $user, ?array $schema = null): AiResponse
    {
        $this->guard();
        $title = is_array($schema) ? (string) ($schema['title'] ?? '') : '';
        $cacheKey = 'ai:gen:'.hash('sha256', $this->primary->providerName().'|'.$title.'|'.$system.'|'.$user);
        $ttl = $title === 'CalendarIntent' ? 3600 : (int) config('sanggah.cache_ttl.explain');

        $payload = Cache::remember($cacheKey, $ttl, function () use ($system, $user, $schema) {
            $response = $this->attempt(fn (AiService $ai) => $ai->generate($system, $user, $schema));

            return [
                'text' => $response->text,
                'json' => $response->json,
                'model' => $response->model,
                'provider' => $response->provider,
            ];
        });

        return new AiResponse($payload['text'], $payload['json'], $payload['model'], $payload['provider'], true);
    }

    private function guard(): void
    {
        if ($this->forceFail || (bool) config('sanggah.ai.force_fail')) {
            throw AiException::unavailable('AI_FORCE_FAIL is enabled.');
        }
        if ($this->primary->providerName() === 'mock') {
            return;
        }
        if ($this->dailyBudget <= 0) {
            return;
        }
        $key = 'ai-budget:'.now()->timezone(config('sanggah.timezone'))->toDateString();
        $used = (int) Cache::get($key, 0);
        if ($used >= $this->dailyBudget) {
            throw AiException::budget();
        }
    }

    /**
     * @template T
     *
     * @param  callable(AiService): T  $call
     * @return T
     */
    private function attempt(callable $call): mixed
    {
        try {
            $result = $call($this->primary);
            $this->spend();

            return $result;
        } catch (Throwable $primaryError) {
            if ($this->secondary === null || $this->secondary->providerName() === 'mock') {
                throw $primaryError instanceof AiException ? $primaryError : AiException::unavailable();
            }
            try {
                $result = $call($this->secondary);
                $this->spend();

                return $result;
            } catch (Throwable $secondaryError) {
                throw $secondaryError instanceof AiException ? $secondaryError : AiException::unavailable();
            }
        }
    }

    private function spend(): void
    {
        if ($this->primary->providerName() === 'mock' || $this->dailyBudget <= 0) {
            return;
        }
        $key = 'ai-budget:'.now()->timezone(config('sanggah.timezone'))->toDateString();
        if (! Cache::has($key)) {
            Cache::put($key, 1, now()->endOfDay());

            return;
        }
        Cache::increment($key);
    }
}
