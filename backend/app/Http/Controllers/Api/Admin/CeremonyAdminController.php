<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ceremony;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CeremonyAdminController extends Controller
{
    public function index()
    {
        $rows = Ceremony::query()->with('sources')->orderBy('date_start')->get()->map(fn (Ceremony $ceremony) => $this->payload($ceremony));

        return ApiResponse::ok(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $ceremony = new Ceremony($data);
        $ceremony->slug = $data['slug'] ?? Str::slug($data['name_en']).'-'.$data['date_start'];
        $this->applyReview($ceremony, $request);
        $ceremony->save();
        $ceremony->sources()->sync($data['source_ids']);
        Cache::flush();

        return ApiResponse::ok(['data' => $this->payload($ceremony->load('sources'))], 201);
    }

    public function update(Request $request, Ceremony $ceremony)
    {
        $data = $this->validated($request, $ceremony->id);
        $ceremony->fill($data);
        if (! empty($data['slug'])) {
            $ceremony->slug = $data['slug'];
        }
        $this->applyReview($ceremony, $request);
        $ceremony->save();
        $ceremony->sources()->sync($data['source_ids']);
        Cache::flush();

        return ApiResponse::ok(['data' => $this->payload($ceremony->load('sources'))]);
    }

    public function destroy(Ceremony $ceremony)
    {
        $ceremony->delete();
        Cache::flush();

        return response()->json(['request_id' => ApiResponse::requestId()], 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'slug' => ['nullable', 'string', 'max:120', Rule::unique('ceremonies', 'slug')->ignore($id)],
            'name_id' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:hari_raya,rerahinan,lokal'],
            'date_start' => ['required', 'date'],
            'date_end' => ['nullable', 'date', 'after_or_equal:date_start'],
            'summary_id' => ['nullable', 'string'],
            'summary_en' => ['nullable', 'string'],
            'tips_id' => ['required', 'string'],
            'tips_en' => ['required', 'string'],
            'road_note_id' => ['nullable', 'string'],
            'road_note_en' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => ['integer', 'exists:sources,id'],
            'ai_drafted' => ['sometimes', 'boolean'],
        ]);
    }

    private function applyReview(Ceremony $ceremony, Request $request): void
    {
        if ($ceremony->ai_drafted && $ceremony->status === 'published' && $ceremony->reviewed_by === null && ! $request->boolean('reviewed')) {
            $ceremony->status = 'draft';
        }
        if ($ceremony->status === 'published') {
            $ceremony->reviewed_by = $request->user()->id;
            $ceremony->published_at = $ceremony->published_at ?? now();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Ceremony $ceremony): array
    {
        return [
            'id' => $ceremony->id,
            'slug' => $ceremony->slug,
            'name_id' => $ceremony->name_id,
            'name_en' => $ceremony->name_en,
            'type' => $ceremony->type,
            'date_start' => $ceremony->date_start->toDateString(),
            'date_end' => $ceremony->date_end?->toDateString(),
            'summary_id' => $ceremony->summary_id,
            'summary_en' => $ceremony->summary_en,
            'tips_id' => $ceremony->tips_id,
            'tips_en' => $ceremony->tips_en,
            'road_note_id' => $ceremony->road_note_id,
            'road_note_en' => $ceremony->road_note_en,
            'status' => $ceremony->status,
            'ai_drafted' => $ceremony->ai_drafted,
            'reviewed_by' => $ceremony->reviewed_by,
            'source_ids' => $ceremony->sources->pluck('id')->all(),
        ];
    }
}
