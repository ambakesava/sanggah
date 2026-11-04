<?php

declare(strict_types=1);

$origins = array_values(array_filter(array_map(
    trim(...),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))
)));

return [
    'corpus_version' => env('CORPUS_VERSION', '2026.1'),
    'rules_version' => env('RULES_VERSION', '2026.1'),
    'confidence_threshold' => (float) env('SCAN_CONFIDENCE_THRESHOLD', 0.6),
    'max_range_days' => (int) env('CALENDAR_MAX_RANGE_DAYS', 62),
    'timezone' => 'Asia/Makassar',

    'scan' => [
        'max_bytes' => (int) env('SCAN_MAX_BYTES', 8 * 1024 * 1024),
        'max_edge' => (int) env('SCAN_MAX_EDGE', 8000),
        'normalize_edge' => 1024,
    ],

    'rate' => [
        'scan_per_minute' => (int) env('SCAN_RATE_PER_MINUTE', 10),
        'scan_per_day' => (int) env('SCAN_RATE_PER_DAY', 100),
        'ask_per_minute' => (int) env('ASK_RATE_PER_MINUTE', 20),
        'feedback_per_hour' => (int) env('FEEDBACK_RATE_PER_HOUR', 30),
        'admin_per_minute' => (int) env('ADMIN_RATE_PER_MINUTE', 60),
        'login_per_minute' => (int) env('LOGIN_RATE_PER_MINUTE', 5),
    ],

    'cache_ttl' => [
        'scan' => (int) env('CACHE_TTL_SCAN', 60 * 60 * 24),
        'explain' => (int) env('CACHE_TTL_EXPLAIN', 60 * 60 * 24 * 7),
        'ceremonies' => (int) env('CACHE_TTL_CEREMONIES', 600),
    ],

    'demo' => [
        'email' => env('DEMO_ADMIN_EMAIL', 'juri@sanggah.test'),
        'password' => env('DEMO_ADMIN_PASSWORD', 'Sanggah-Juri-2026'),
        'name' => env('DEMO_ADMIN_NAME', 'Juri AIWIC'),
    ],

    'cors_origins' => $origins,

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'timeout' => (int) env('AI_TIMEOUT_S', 20),
        'max_retry' => (int) env('AI_MAX_RETRY', 1),
        'daily_budget' => (int) env('AI_DAILY_BUDGET_CALLS', 200),
        'force_fail' => filter_var(env('AI_FORCE_FAIL', false), FILTER_VALIDATE_BOOL),
        'temperature' => 0.2,
        'gemini' => [
            'key' => env('GEMINI_API_KEY', ''),
            'base' => rtrim((string) env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'),
            'text_model' => env('GEMINI_TEXT_MODEL', 'gemini-2.5-flash-lite'),
            'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash-lite'),
        ],
        'qwen' => [
            'key' => env('QWEN_API_KEY', ''),
            'base' => rtrim((string) env('QWEN_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1'), '/'),
            'text_model' => env('QWEN_TEXT_MODEL', 'qwen3-vl-flash'),
            'vision_model' => env('QWEN_VISION_MODEL', 'qwen3-vl-flash'),
        ],
    ],

    'disclaimer' => [
        'calendar' => [
            'id' => 'Hasil ini bersifat informatif. Untuk keputusan penting, konsultasikan dengan pemangku atau sulinggih setempat. Praktik dapat berbeda menurut desa, kala, dan patra.',
            'en' => 'This result is informational. For an important decision, consult a local pemangku or sulinggih. Practice can differ by place, time, and circumstance (desa kala patra).',
        ],
        'culture' => [
            'id' => 'Praktik dapat berbeda antar desa (desa kala patra). Penjelasan ini bukan keputusan adat.',
            'en' => 'Practices may vary between villages (desa kala patra). This is not a customary ruling.',
        ],
    ],
];
