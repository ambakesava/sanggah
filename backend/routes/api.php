<?php

use App\Http\Controllers\Api\Admin\CatalogAdminController;
use App\Http\Controllers\Api\Admin\CeremonyAdminController;
use App\Http\Controllers\Api\AskController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CeremonyController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\ExplainController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ObjectController;
use App\Http\Controllers\Api\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::get('/objects', [ObjectController::class, 'index']);
Route::post('/scan', [ScanController::class, 'store'])->middleware('throttle:scan');
Route::post('/explain', [ExplainController::class, 'store'])->middleware('throttle:ask');
Route::post('/ask', [AskController::class, 'store'])->middleware('throttle:ask');
Route::get('/calendar/{date}', [CalendarController::class, 'show'])->where('date', '\d{4}-\d{2}-\d{2}');

Route::get('/ceremonies', [CeremonyController::class, 'index']);
Route::get('/ceremonies/{slug}.ics', [CeremonyController::class, 'ics'])->where('slug', '[A-Za-z0-9\-]+');
Route::get('/ceremonies/{slug}', [CeremonyController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+');

Route::post('/feedback', [FeedbackController::class, 'store'])->middleware('throttle:feedback');
Route::post('/consents', [ConsentController::class, 'store']);
Route::delete('/consents/{id}', [ConsentController::class, 'destroy'])->where('id', '[0-9a-fA-F\-]+');

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'admin', 'throttle:admin'])->prefix('admin')->group(function () {
    Route::get('/ceremonies', [CeremonyAdminController::class, 'index']);
    Route::post('/ceremonies', [CeremonyAdminController::class, 'store']);
    Route::put('/ceremonies/{ceremony}', [CeremonyAdminController::class, 'update']);
    Route::delete('/ceremonies/{ceremony}', [CeremonyAdminController::class, 'destroy']);

    Route::get('/objects', [CatalogAdminController::class, 'objects']);
    Route::put('/objects/{object}', [CatalogAdminController::class, 'updateObject']);

    Route::get('/sources', [CatalogAdminController::class, 'sources']);
    Route::post('/sources', [CatalogAdminController::class, 'storeSource']);
    Route::put('/sources/{source}', [CatalogAdminController::class, 'updateSource']);
    Route::delete('/sources/{source}', [CatalogAdminController::class, 'destroySource']);
    Route::post('/sources/{source}/ingest', [CatalogAdminController::class, 'ingest']);

    Route::get('/calendar-rules', [CatalogAdminController::class, 'rules']);
    Route::post('/calendar-rules', [CatalogAdminController::class, 'storeRule']);
    Route::put('/calendar-rules/{rule}', [CatalogAdminController::class, 'updateRule']);
    Route::delete('/calendar-rules/{rule}', [CatalogAdminController::class, 'destroyRule']);

    Route::get('/sasih', [CatalogAdminController::class, 'sasih']);
    Route::post('/sasih', [CatalogAdminController::class, 'upsertSasih']);
    Route::get('/feedback', [CatalogAdminController::class, 'feedbackSummary']);
});
