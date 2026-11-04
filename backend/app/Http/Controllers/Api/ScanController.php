<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Services\Scan\ScanService;
use App\Support\ApiResponse;
use App\Support\Locale;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __construct(private readonly ScanService $scans) {}

    public function store(Request $request)
    {
        $lang = Locale::fromRequest($request);
        $request->validate([
            'image' => ['required', 'file'],
            'store_photo' => ['sometimes', 'boolean'],
        ]);
        $file = $request->file('image');
        $store = $request->boolean('store_photo');
        try {
            $result = $this->scans->scan(
                (string) file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                $lang,
                $store,
                (string) $request->attributes->get('request_id')
            );
        } catch (AiException $exception) {
            return ApiResponse::error(
                $exception->errorCode,
                ApiResponse::message($exception->errorCode, $lang),
                $exception->status,
                $exception->retryable,
                ScanService::manualFallback()
            );
        }

        return ApiResponse::ok($result);
    }
}
