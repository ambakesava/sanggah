<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id', 'Retry-After'],
    'max_age' => 0,
    'supports_credentials' => false,
];
