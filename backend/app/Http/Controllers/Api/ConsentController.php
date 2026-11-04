<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use App\Models\Scan;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConsentController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'purpose' => ['sometimes', 'in:store_photo'],
        ]);
        $consent = Consent::query()->create([
            'uuid' => (string) Str::uuid(),
            'subject_type' => 'session',
            'subject_ref' => $request->attributes->get('request_id'),
            'purpose' => $data['purpose'] ?? 'store_photo',
            'granted' => true,
            'granted_at' => now(),
        ]);

        return ApiResponse::ok(['id' => $consent->uuid], 201);
    }

    public function destroy(Request $request, string $id)
    {
        $lang = Locale::fromRequest($request);
        $consent = Consent::query()->where('uuid', $id)->first();
        if ($consent === null) {
            return ApiResponse::error('NOT_FOUND', ApiResponse::message('NOT_FOUND', $lang), 404);
        }
        if ($consent->image_path) {
            Storage::disk('local')->delete($consent->image_path);
            Scan::query()->where('image_path', $consent->image_path)->update(['image_path' => null]);
        }
        $consent->update([
            'granted' => false,
            'revoked_at' => now(),
            'image_path' => null,
        ]);

        return ApiResponse::ok(['deleted' => true]);
    }
}
