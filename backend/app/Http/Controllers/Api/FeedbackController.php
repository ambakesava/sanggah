<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CulturalObject;
use App\Models\Feedback;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'request_id' => ['nullable', 'string', 'max:80'],
            'feature' => ['required', 'in:scan,explain,ask'],
            'object_slug' => ['nullable', 'string'],
            'rating' => ['required', 'in:helpful,wrong'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $objectId = null;
        if (! empty($data['object_slug'])) {
            $objectId = CulturalObject::query()->where('slug', $data['object_slug'])->value('id');
        }
        $row = Feedback::query()->create([
            'request_id' => $data['request_id'] ?? $request->attributes->get('request_id'),
            'feature' => $data['feature'],
            'object_id' => $objectId,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return ApiResponse::ok(['id' => $row->id], 201);
    }
}
