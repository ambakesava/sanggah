<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CulturalObject;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;

class ObjectController extends Controller
{
    public function index(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $data = CulturalObject::query()
            ->where('is_active', true)
            ->orderBy('name_id')
            ->get()
            ->map(fn (CulturalObject $object) => [
                'slug' => $object->slug,
                'name' => $object->nameFor($lang),
                'name_id' => $object->name_id,
                'name_en' => $object->name_en,
                'category' => $object->category,
            ]);

        return ApiResponse::ok(['data' => $data]);
    }
}
