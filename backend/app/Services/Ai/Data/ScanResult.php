<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

final class ScanResult
{
    /**
     * @param  list<string>  $visualCues
     * @param  list<array{class: string, confidence: float}>  $alternatives
     */
    public function __construct(
        public readonly string $class,
        public readonly float $confidence,
        public readonly array $visualCues = [],
        public readonly array $alternatives = [],
        public readonly bool $containsPersonAsSubject = false,
        public readonly string $model = 'mock',
        public readonly string $provider = 'mock',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'confidence' => $this->confidence,
            'visual_cues' => $this->visualCues,
            'alternatives' => $this->alternatives,
            'contains_person_as_subject' => $this->containsPersonAsSubject,
            'model' => $this->model,
            'provider' => $this->provider,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $model = 'mock', string $provider = 'mock'): self
    {
        $alternatives = [];
        foreach ((array) ($data['alternatives'] ?? []) as $alt) {
            if (! is_array($alt) || ! isset($alt['class'])) {
                continue;
            }
            $alternatives[] = [
                'class' => (string) $alt['class'],
                'confidence' => self::clamp($alt['confidence'] ?? 0),
            ];
        }

        $cues = [];
        foreach ((array) ($data['visual_cues'] ?? []) as $cue) {
            if (is_string($cue) && $cue !== '') {
                $cues[] = $cue;
            }
        }

        return new self(
            class: (string) ($data['class'] ?? 'unknown'),
            confidence: self::clamp($data['confidence'] ?? 0),
            visualCues: array_slice($cues, 0, 5),
            alternatives: array_slice($alternatives, 0, 3),
            containsPersonAsSubject: (bool) ($data['contains_person_as_subject'] ?? false),
            model: $model,
            provider: $provider,
        );
    }

    public static function clamp(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $number));
    }
}
