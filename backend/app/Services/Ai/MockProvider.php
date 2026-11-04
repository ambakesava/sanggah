<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Data\AiResponse;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;

/**
 * Offline provider. Vision uses the filename as a demo hint so judges can
 * exercise the flow without an API key. It does not invent calendar dates.
 */
final class MockProvider implements AiService
{
    public function __construct(private readonly OfflineIntentParser $intents) {}

    public function providerName(): string
    {
        return 'mock';
    }

    public function identify(ImageInput $image, string $lang, array $classes): ScanResult
    {
        $known = [];
        foreach ($classes as $class) {
            $known[$class['slug']] = $class;
        }
        $filename = mb_strtolower((string) $image->filename);
        $filename = str_replace(['-', ' '], '_', $filename);

        foreach ($known as $slug => $class) {
            if ($slug !== '' && str_contains($filename, $slug)) {
                $alts = [];
                foreach (array_slice(array_keys($known), 0, 2) as $other) {
                    if ($other !== $slug) {
                        $alts[] = ['class' => $other, 'confidence' => 0.22];
                    }
                }

                return new ScanResult(
                    class: $slug,
                    confidence: 0.91,
                    visualCues: [$class['visual_hint'] !== '' ? $class['visual_hint'] : 'filename hint'],
                    alternatives: $alts,
                    containsPersonAsSubject: false,
                    model: 'mock',
                    provider: 'mock',
                );
            }
        }

        $candidates = array_slice(array_keys($known), 0, 3);
        $alts = [];
        $score = 0.48;
        foreach ($candidates as $slug) {
            $alts[] = ['class' => $slug, 'confidence' => $score];
            $score -= 0.1;
        }

        return new ScanResult(
            class: 'unknown',
            confidence: 0.41,
            visualCues: ['no filename hint', 'offline mock'],
            alternatives: $alts,
            containsPersonAsSubject: false,
            model: 'mock',
            provider: 'mock',
        );
    }

    public function embed(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }

    public function generate(string $system, string $user, ?array $schema = null): AiResponse
    {
        $title = is_array($schema) ? (string) ($schema['title'] ?? '') : '';
        if ($title === 'CalendarIntent' || str_contains($system, 'CalendarIntent')) {
            $question = $this->field($user, 'question');
            $lang = $this->field($user, 'lang') ?: 'id';
            $json = $this->intents->parse($question, $lang);

            return new AiResponse(json_encode($json, JSON_UNESCAPED_UNICODE) ?: '{}', $json, 'mock', 'mock');
        }

        return new AiResponse('', null, 'mock', 'mock');
    }

    private function field(string $user, string $name): string
    {
        if (preg_match('/^'.$name.':\s*(.*)$/mi', $user, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }
}
