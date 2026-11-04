<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiException;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Alibaba Model Studio international (Singapore) OpenAI-compatible endpoint.
 */
final class QwenProvider implements AiService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $textModel,
        private readonly string $visionModel,
        private readonly int $timeout,
        private readonly int $maxRetry,
        private readonly PromptLibrary $prompts,
    ) {}

    public function providerName(): string
    {
        return 'qwen';
    }

    public function identify(ImageInput $image, string $lang, array $classes): ScanResult
    {
        $system = $this->prompts->get('scan');
        $userText = "lang: {$lang}\nclasses:\n".json_encode($classes, JSON_UNESCAPED_UNICODE);
        $raw = $this->chat($system, [
            ['type' => 'text', 'text' => $userText],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$image->mime.';base64,'.$image->base64()]],
        ], $this->visionModel);
        $json = JsonExtractor::decode($raw);
        if ($json === null) {
            return new ScanResult('unknown', 0, ['invalid json'], [], false, $this->visionModel, 'qwen');
        }
        $result = ScanResult::fromArray($json, $this->visionModel, 'qwen');
        $allowed = [];
        foreach ($classes as $class) {
            $allowed[$class['slug']] = true;
        }
        if (! isset($allowed[$result->class])) {
            return new ScanResult('unknown', $result->confidence, $result->visualCues, $result->alternatives, $result->containsPersonAsSubject, $result->model, 'qwen');
        }

        return $result;
    }

    public function embed(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }

    public function generate(string $system, string $user, ?array $schema = null): AiResponse
    {
        $raw = $this->chat($system, $user, $this->textModel);

        return new AiResponse($raw, JsonExtractor::decode($raw), $this->textModel, 'qwen');
    }

    /**
     * @param  string|list<array<string, mixed>>  $userContent
     */
    private function chat(string $system, string|array $userContent, string $model): string
    {
        $payload = [
            'model' => $model,
            'temperature' => (float) config('sanggah.ai.temperature', 0.2),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userContent],
            ],
        ];
        $body = $this->post($this->baseUrl.'/chat/completions', $payload);
        $text = $body['choices'][0]['message']['content'] ?? null;
        if (is_array($text)) {
            $chunks = [];
            foreach ($text as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $chunks[] = $part['text'];
                }
            }
            $text = implode("\n", $chunks);
        }
        if (! is_string($text) || $text === '') {
            throw AiException::upstream('Qwen returned an empty response.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload): array
    {
        $attempts = max(1, $this->maxRetry + 1);
        $lastStatus = 0;
        $timedOut = false;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withToken($this->apiKey)
                    ->acceptJson()
                    ->post($url, $payload);
                if ($response->successful()) {
                    $json = $response->json();

                    return is_array($json) ? $json : [];
                }
                $lastStatus = $response->status();
                if ($lastStatus < 500 && $lastStatus !== 429) {
                    break;
                }
            } catch (Throwable $e) {
                $timedOut = str_contains(strtolower($e->getMessage()), 'timed') || str_contains(strtolower($e->getMessage()), 'curl error 28');
            }
        }
        if ($timedOut) {
            throw AiException::timeout();
        }
        throw AiException::upstream('Qwen HTTP '.$lastStatus);
    }
}
