<?php

return [
    'enabled' => env('GOOGLE_FACT_CHECK_ENABLED', false),

    'google' => [
        'api_key' => env('GOOGLE_FACT_CHECK_API_KEY'),
        'api_base_url' => env('GOOGLE_FACT_CHECK_API_BASE_URL', 'https://factchecktools.googleapis.com/v1alpha1/claims:search'),
        'timeout' => (int) env('GOOGLE_FACT_CHECK_TIMEOUT', 20),
        'connect_timeout' => (int) env('GOOGLE_FACT_CHECK_CONNECT_TIMEOUT', 5),
    ],
];
