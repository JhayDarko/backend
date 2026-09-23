<?php

return [
    'enabled' => env('SEARCH_PROVIDER_ENABLED', false),
    'default' => env('SEARCH_PROVIDER_DEFAULT', 'tavily'),
    'fallback' => env('SEARCH_PROVIDER_FALLBACK', 'brave'),
    'max_results' => (int) env('SEARCH_PROVIDER_MAX_RESULTS', 10),
    'timeout' => (int) env('SEARCH_PROVIDER_TIMEOUT', 10),
    'connect_timeout' => (int) env('SEARCH_PROVIDER_CONNECT_TIMEOUT', 5),

    'providers' => [
        'tavily' => [
            'enabled' => env('TAVILY_SEARCH_ENABLED', false),
            'api_key' => env('TAVILY_API_KEY'),
            'api_base_url' => env('TAVILY_API_BASE_URL', 'https://api.tavily.com/search'),
            'max_results' => (int) env('TAVILY_MAX_RESULTS', 10),
            'timeout' => (int) env('TAVILY_TIMEOUT', 10),
            'connect_timeout' => (int) env('TAVILY_CONNECT_TIMEOUT', 5),
        ],
        'brave' => [
            'enabled' => env('BRAVE_SEARCH_ENABLED', false),
            'api_key' => env('BRAVE_API_KEY'),
            'api_base_url' => env('BRAVE_API_BASE_URL', 'https://api.search.brave.com/res/v1/web/search'),
            'max_results' => (int) env('BRAVE_MAX_RESULTS', 10),
            'timeout' => (int) env('BRAVE_TIMEOUT', 10),
            'connect_timeout' => (int) env('BRAVE_CONNECT_TIMEOUT', 5),
        ],
    ],
];
