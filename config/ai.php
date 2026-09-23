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
    'timeout' => (int) env('AI_PROVIDER_TIMEOUT', 60),

    'bert' => [
        'base_url' => env('BERT_CLASSIFIER_URL', 'http://ml-service:8000'),
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
        'api_base_url' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-3.6-flash'),
        'fallback_models' => array_filter(array_map(
            'trim',
            explode(',', env('GEMINI_FALLBACK_MODELS', 'gemini-3.7-flash,gemini-3.5-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite')),
        )),
        'temperature' => (float) env('GEMINI_TEMPERATURE', 0.7),
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
        'model' => env('OPENAI_MODEL', 'gpt-4'),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.7),
    ],
];
