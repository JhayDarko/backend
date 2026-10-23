<?php

declare(strict_types=1);

// Keep Larastan's application bootstrap independent of local .env settings.
$analysisEnvironment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'AI_PROVIDER' => 'gemini',
    'AI_FALLBACK_PROVIDER' => 'openai',
    'AI_FALLBACK_ENABLED' => 'true',
    'CLASSIFIER_PROVIDER' => 'bert',
];

foreach ($analysisEnvironment as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
