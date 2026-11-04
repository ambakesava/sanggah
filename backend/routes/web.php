<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'Sanggah API',
        'product' => 'Sanggah: Bali Culture Companion',
        'health' => url('/api/health'),
        'note' => 'The Indonesian UI lives in the frontend app. This host is the API.',
    ]);
});
