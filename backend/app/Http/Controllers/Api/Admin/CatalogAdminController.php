<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CalendarRule;
use App\Models\CulturalObject;
use App\Models\SasihDay;
use App\Models\Source;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CatalogAdminController extends Controller
{
    public function objects()
    {
        return ApiResponse::ok(['data' => CulturalObject::query()->orderBy('slug')->get()]);
    }

    public function updateObject(Request $request, CulturalObject $object)
    {
        $data = $request->validate([
            'name_id' => ['sometimes', 'string'],
            'name_en' => ['sometimes', 'string'],
            'visual_hint' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'category' => ['sometimes', 'string'],
        ]);
        $object->fill($data)->save();

        return ApiResponse::ok(['data' => $object]);
    }

    public function sources()
    {
        return ApiResponse::ok(['data' => Source::query()->orderBy('code')->get()]);
    }

    public function storeSource(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'unique:sources,code'],
            'title' => ['required', 'string'],
            'publisher' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'lang' => ['nullable', 'string'],
            'license' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);
        $source = Source::query()->create($data);

        return ApiResponse::ok(['data' => $source], 201);
    }

    public function updateSource(Request $request, Source $source)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string'],
            'publisher' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'license' => ['nullable', 'string'],
        ]);
        $source->fill($data)->save();

        return ApiResponse::ok(['data' => $source]);
    }

    public function destroySource(Source $source)
    {
        $source->delete();

        return response()->json(['request_id' => ApiResponse::requestId()], 204);
    }

    public function ingest(Source $source)
    {
        $count = 0;
        foreach ($source->chunks as $chunk) {
            $chunk->content_hash = hash('sha256', $chunk->content);
            $chunk->embedding_model = 'lexical-v1';
            $chunk->corpus_version = (string) config('sanggah.corpus_version');
            $chunk->token_count = str_word_count($chunk->content);
            $chunk->save();
            $count++;
        }

        return ApiResponse::ok([
            'ingested' => $count,
            'embedding_model' => 'lexical-v1',
            'note' => 'MVP retrieval is lexical. vector_id stays empty until a vector store is attached.',
        ]);
    }

    public function rules()
    {
        return ApiResponse::ok(['data' => CalendarRule::query()->orderBy('code')->get()]);
    }

    public function storeRule(Request $request)
    {
        $data = $this->ruleData($request);
        $rule = CalendarRule::query()->create($data);

        return ApiResponse::ok(['data' => $rule], 201);
    }

    public function updateRule(Request $request, CalendarRule $rule)
    {
        $rule->fill($this->ruleData($request, false))->save();

        return ApiResponse::ok(['data' => $rule]);
    }

    public function destroyRule(CalendarRule $rule)
    {
        $rule->delete();

        return response()->json(['request_id' => ApiResponse::requestId()], 204);
    }

    public function sasih(Request $request)
    {
        $query = SasihDay::query()->orderBy('date');
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->query('to'));
        }

        return ApiResponse::ok(['data' => $query->limit(400)->get()]);
    }

    public function upsertSasih(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'sasih' => ['required', 'string'],
            'penanggal_pangelong' => ['required', 'string'],
            'is_purnama' => ['sometimes', 'boolean'],
            'is_tilem' => ['sometimes', 'boolean'],
            'observances' => ['nullable', 'array'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
        ]);
        $row = SasihDay::query()->updateOrCreate(['date' => $data['date']], $data);

        return ApiResponse::ok(['data' => $row]);
    }

    public function feedbackSummary()
    {
        $rows = \App\Models\Feedback::query()
            ->selectRaw('object_id, rating, count(*) as total')
            ->groupBy('object_id', 'rating')
            ->get();

        return ApiResponse::ok(['data' => $rows]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleData(Request $request, bool $creating = true): array
    {
        $rules = [
            'name_id' => [$creating ? 'required' : 'sometimes', 'string'],
            'name_en' => [$creating ? 'required' : 'sometimes', 'string'],
            'rule_type' => [$creating ? 'required' : 'sometimes', 'in:rerahinan,dewasa_ayu,dewasa_ala'],
            'activity_tags' => [$creating ? 'required' : 'sometimes', 'array'],
            'conditions' => [$creating ? 'required' : 'sometimes', 'array'],
            'effect' => [$creating ? 'required' : 'sometimes', 'in:good,bad,info'],
            'weight' => ['sometimes', 'integer'],
            'source_id' => ['nullable', 'integer', 'exists:sources,id'],
            'status' => ['sometimes', 'in:draft,validated'],
            'code' => [$creating ? 'required' : 'sometimes', 'string'],
        ];
        $data = $request->validate($rules);
        if ($creating && empty($data['code'])) {
            $data['code'] = Str::slug((string) $request->input('name_en'));
        }
        if (($data['status'] ?? null) === 'validated') {
            $data['validated_by'] = $request->user()->email;
        }
        $data['rules_version'] = (string) config('sanggah.rules_version');

        return $data;
    }
}
