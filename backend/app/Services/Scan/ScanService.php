<?php

declare(strict_types=1);

namespace App\Services\Scan;

use App\Exceptions\AiException;
use App\Models\Consent;
use App\Models\CulturalObject;
use App\Models\Scan;
use App\Services\Ai\AiService;
use App\Services\Ai\Data\ImageInput;
use App\Services\Ai\Data\ScanResult;
use App\Services\Explain\ExplainService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ScanService
{
    public function __construct(
        private readonly AiService $ai,
        private readonly ImageProcessor $images,
        private readonly ExplainService $explain,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(string $binary, ?string $filename, string $lang, bool $storePhoto, ?string $requestId): array
    {
        $started = (int) round(microtime(true) * 1000);
        $normalized = $this->images->normalize($binary, $lang);
        $classes = CulturalObject::query()
            ->where('is_active', true)
            ->orderBy('slug')
            ->get(['slug', 'name_en', 'visual_hint'])
            ->map(fn (CulturalObject $object) => [
                'slug' => $object->slug,
                'name' => $object->name_en,
                'visual_hint' => (string) $object->visual_hint,
            ])
            ->all();

        $threshold = (float) config('sanggah.confidence_threshold');
        $result = $this->ai->identify(new ImageInput($normalized['bytes'], $normalized['mime'], $filename), $lang, $classes);
        $object = $result->class !== 'unknown'
            ? CulturalObject::query()->where('slug', $result->class)->where('is_active', true)->first()
            : null;

        $identified = $object !== null
            && $result->confidence >= $threshold
            && ! $result->containsPersonAsSubject
            && $result->class !== 'unknown';

        $payload = [
            'photo_stored' => false,
            'consent_id' => null,
        ];

        if ($identified) {
            $explanation = $this->explain->explain($object, $lang, null);
            $payload['status'] = 'identified';
            $payload['object'] = [
                'slug' => $object->slug,
                'name' => $object->nameFor($lang),
                'confidence' => round($result->confidence, 4),
                'visual_cues' => $result->visualCues,
            ];
            $payload['explanation'] = $explanation;
        } else {
            $payload['status'] = 'needs_confirmation';
            $payload['message'] = $lang === 'en'
                ? 'We are not sure. Pick the closest match or take another photo.'
                : 'Kami tidak yakin. Pilih yang paling dekat, atau ambil foto lagi.';
            $payload['candidates'] = $this->candidates($result, $lang);
        }

        $consent = null;
        if ($storePhoto) {
            $consent = $this->storeConsent($normalized['bytes'], $requestId);
            $payload['photo_stored'] = true;
            $payload['consent_id'] = $consent->uuid;
        }

        Scan::query()->create([
            'request_id' => $requestId ?: (string) Str::uuid(),
            'object_id' => $object?->id,
            'status' => $payload['status'],
            'confidence' => $result->confidence,
            'image_hash' => $normalized['hash'],
            'image_path' => $consent?->image_path,
            'lang' => $lang,
            'model' => $result->provider.'/'.$result->model,
            'latency_ms' => (int) round(microtime(true) * 1000) - $started,
        ]);

        return $payload;
    }

    /**
     * @return list<array{slug: string, name: string, confidence: float}>
     */
    private function candidates(ScanResult $result, string $lang): array
    {
        $rows = $result->alternatives;
        if ($rows === [] && $result->class !== 'unknown') {
            $rows[] = ['class' => $result->class, 'confidence' => $result->confidence];
        }
        $out = [];
        foreach ($rows as $row) {
            $object = CulturalObject::query()->where('slug', $row['class'])->where('is_active', true)->first();
            if ($object === null) {
                continue;
            }
            $out[] = [
                'slug' => $object->slug,
                'name' => $object->nameFor($lang),
                'confidence' => round((float) $row['confidence'], 4),
            ];
        }

        return $out;
    }

    private function storeConsent(string $jpeg, ?string $requestId): Consent
    {
        $uuid = (string) Str::uuid();
        $path = 'scans/'.$uuid.'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        return Consent::query()->create([
            'uuid' => $uuid,
            'subject_type' => 'session',
            'subject_ref' => $requestId,
            'purpose' => 'store_photo',
            'granted' => true,
            'granted_at' => now(),
            'image_path' => $path,
        ]);
    }

    public static function manualFallback(): array
    {
        return [
            'type' => 'manual_select',
            'objects_endpoint' => '/api/objects',
        ];
    }

    public function unavailable(AiException $exception): never
    {
        throw $exception;
    }
}
