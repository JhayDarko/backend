<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | Specifies which AI provider to use for claim extraction and analysis.
    | Supported: 'gemini', 'openai' (future), 'local' (future)
    |
    */
    'provider' => env('AI_PROVIDER', 'gemini'),
    'fallback_enabled' => (bool) env('AI_FALLBACK_ENABLED', true),
    'fallback_provider' => env('AI_FALLBACK_PROVIDER', 'openai'),

    'classifier' => [
        'provider' => env('CLASSIFIER_PROVIDER', 'bert'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompt Version
    |--------------------------------------------------------------------------
    |
    | Version identifier for claim extraction prompts.
    | Used for reproducibility and audit trails.
    |
    */
    'prompt_version' => env('CLAIM_EXTRACTION_PROMPT_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | Timeout in seconds for AI provider requests.
    |
    */
    'timeout' => (int) env('AI_PROVIDER_TIMEOUT', 120),
    'rate_limit_backoff' => (int) env('AI_RATE_LIMIT_BACKOFF', 60),

    'bert' => [
        /*
         * From Windows (Laravel outside Docker): http://127.0.0.1:<published-port>
         * From inside Docker (Laravel in container): http://ml-service:8000
         * Set BERT_SERVICE_URL accordingly in .env — never hardcode the hostname.
         */
        'base_url' => env('BERT_SERVICE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (int) env('BERT_TIMEOUT', 120),
        'connect_timeout' => (int) env('BERT_CONNECT_TIMEOUT', 10),
        'model' => env('BERT_CLASSIFIER_MODEL', 'Juanillaberia/spanish-fake-news-classifier'),
        'version' => env('BERT_CLASSIFIER_VERSION', '1.0.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Google Gemini API provider.
    |
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'api_keys' => array_values(array_filter([
            env('GEMINI_API_KEY'),
            env('GEMINI_API_KEY_2'),
            env('GEMINI_API_KEY_3'),
        ])),
        'api_base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'fallback_models' => array_filter(array_map(
            'trim',
            explode(',', env('GEMINI_FALLBACK_MODELS', 'gemini-3.7-flash,gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite')),
        )),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.7),
        // Per-request timeouts (seconds); AI_PROVIDER_TIMEOUT is too long to detect a stalled model.
        'extraction_timeout' => (int) env('GEMINI_EXTRACTION_TIMEOUT', 60),
        'analyst_timeout' => (int) env('GEMINI_ANALYST_TIMEOUT', 150),
        'ocr_timeout' => (int) env('GEMINI_OCR_TIMEOUT', 60),
        // Seconds an overloaded model (503/timeout) is skipped by later requests.
        'unavailable_cooldown' => (int) env('GEMINI_UNAVAILABLE_COOLDOWN', 120),
        // Optional (minimal|low|medium|high): lower reasoning effort makes extraction noticeably faster.
        'extraction_thinking_level' => env('GEMINI_EXTRACTION_THINKING_LEVEL'),
    ],

    'extraction' => [
        'max_claims' => (int) env('CLAIM_EXTRACTION_MAX_CLAIMS', 10),
    ],

    'ocr' => [
        'base_url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:8002'),
        'timeout' => (int) env('OCR_TIMEOUT', 240),
        'connect_timeout' => (int) env('OCR_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Configuration (Future)
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenAI API provider (reserved for future use).
    |
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'api_base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.2),
        'timeout' => (int) env('OPENAI_TIMEOUT', 120),
    ],
];
