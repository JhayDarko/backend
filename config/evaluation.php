<?php

return [
    'active' => false,
    'process' => env('EVAL_PROCESS', false),
    'mode' => env('EVAL_MODE', 'production'),
    'no_factcheck' => env('EVAL_NO_FACTCHECK', false),
    'excluded_domains' => json_decode((string) env('EVAL_EXCLUDED_DOMAINS', '[]'), true) ?: [],
    'case_id' => env('EVAL_CASE_ID'),
    'log_path' => env('EVAL_LOG_PATH'),
    'fake_providers' => env('EVAL_FAKE_PROVIDERS', false),
    'test_fast_backoff' => env('EVAL_TEST_FAST_BACKOFF', false),
    'database' => [
        'connection' => env('EVAL_DB_CONNECTION'),
        'host' => env('EVAL_DB_HOST'),
        'port' => env('EVAL_DB_PORT'),
        'database' => env('EVAL_DB_DATABASE'),
        'username' => env('EVAL_DB_USERNAME'),
        'password' => env('EVAL_DB_PASSWORD'),
    ],
];
