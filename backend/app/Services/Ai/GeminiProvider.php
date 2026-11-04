<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\AiException;
use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiProvider implements AiService
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
        return 'gemini';
    }

    public function identify(ImageInput $image, string $lang, array $classes): ScanResult
    {
        $system = $this->prompts->get('scan');
        $user = "lang: {$lang}\nclasses:\n".json_encode($classes, JSON_UNESCAPED_UNICODE);
        $raw = $this->generateContent($system, $user, $this->visionModel, $image);
        $json = JsonExtractor::decode($raw);
        if ($json === null) {
            return new ScanResult('unknown', 0, ['invalid json'], [], false, $this->visionModel, 'gemini');
        }

        return $this->constrain(ScanResult::fromArray($json, $this->visionModel, 'gemini'), $classes);
    }

    public function embed(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }

    public function generate(string $system, string $user, ?array $schema = null): AiResponse
    {
        $raw = $this->generateContent($system, $user, $this->textModel, null);
        $json = JsonExtractor::decode($raw);

        return new AiResponse($raw, $json, $this->textModel, 'gemini');
    }

    private function generateContent(string $system, string $user, string $model, ?ImageInput $image): string
    {
        $parts = [['text' => $user]];
        if ($image !== null) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image->mime,
                    'data' => $image->base64(),
                ],
            ];
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => (float) config('sanggah.ai.temperature', 0.2),
                'responseMimeType' => 'application/json',
            ],
        ];
        $url = $this->baseUrl.'/models/'.$model.':generateContent';
        $body = $this->post($url, $payload, ['x-goog-api-key' => $this->apiKey]);
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! is_string($text)) {
            throw AiException::upstream('Gemini returned an empty response.');
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function post(string $url, array $payload, array $headers): array
    {
        $attempts = max(1, $this->maxRetry + 1);
        $lastStatus = 0;
        $timedOut = false;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders($headers)
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
                $lastStatus = 0;
            }
        }
        if ($timedOut) {
            throw AiException::timeout();
        }
        throw AiException::upstream('Gemini HTTP '.$lastStatus);
    }

    /**
     * @param  list<array{slug: string, name: string, visual_hint: string}>  $classes
     */
    private function constrain(ScanResult $result, array $classes): ScanResult
    {
        $allowed = [];
        foreach ($classes as $class) {
            $allowed[$class['slug']] = true;
        }
        $class = isset($allowed[$result->class]) ? $result->class : 'unknown';
        $alts = [];
        foreach ($result->alternatives as $alt) {
            if (isset($allowed[$alt['class']])) {
                $alts[] = $alt;
            }
        }
        if ($class === 'unknown' && $result->class !== 'unknown') {
            array_unshift($alts, ['class' => $result->class, 'confidence' => $result->confidence]);
        }

        return new ScanResult($class, $result->confidence, $result->visualCues, array_slice($alts, 0, 3), $result->containsPersonAsSubject, $result->model, $result->provider);
    }
}
