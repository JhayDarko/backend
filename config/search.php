<?php

return [
    'enabled' => env('SEARCH_PROVIDER_ENABLED', false),
    'default' => env('SEARCH_PROVIDER_DEFAULT', 'tavily'),
    'fallback' => env('SEARCH_PROVIDER_FALLBACK', 'brave'),
    'max_results' => (int) env('SEARCH_PROVIDER_MAX_RESULTS', 10),
    'timeout' => (int) env('SEARCH_PROVIDER_TIMEOUT', 10),
    'connect_timeout' => (int) env('SEARCH_PROVIDER_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Evidence source policy
    |--------------------------------------------------------------------------
    |
    | fetch_results: how many results are requested per claim (extra ones
    | compensate for filtered domains). evidence_per_claim: how many are kept.
    | blocked_domains: social networks / user-generated platforms, never used as
    | evidence (subdomains included). trusted_domains: extra credible publishers
    | (news agencies, institutions) on top of fact-checkers, mainstream media and
    | .gov/.gob/.edu domains known to SourceAnalysisService. Trusted sources are
    | ranked first; with SEARCH_TRUSTED_ONLY=true only trusted sources are kept.
    |
    */
    'fetch_results' => (int) env('SEARCH_FETCH_RESULTS', 15),
    'evidence_per_claim' => (int) env('SEARCH_EVIDENCE_PER_CLAIM', 8),
    'trusted_only' => (bool) env('SEARCH_TRUSTED_ONLY', false),
    'blocked_domains' => array_values(array_unique(array_filter(array_map('trim', array_merge([
        'facebook.com', 'fb.com', 'fb.watch', 'instagram.com', 'threads.net', 'x.com', 'twitter.com', 't.co',
        'tiktok.com', 'youtube.com', 'youtu.be', 'reddit.com', 'redd.it', 'linkedin.com', 'pinterest.com',
        'snapchat.com', 'telegram.org', 't.me', 'telegram.me', 'whatsapp.com', 'wa.me', 'discord.com', 'discord.gg',
        'tumblr.com', 'quora.com', 'vk.com', 'weibo.com', 'bsky.app', 'mastodon.social', 'truthsocial.com',
        'twitch.tv', 'medium.com', 'blogspot.com', 'wordpress.com', 'linktr.ee',
    ], explode(',', (string) env('SEARCH_EXTRA_BLOCKED_DOMAINS', ''))))))),
    'trusted_domains' => array_values(array_unique(array_filter(array_map('trim', array_merge([
        'efe.com', 'europapress.es', 'infobae.com', 'france24.com', 'dw.com', 'rtve.es', 'aljazeera.com',
        'elcolombiano.com', 'semana.com', 'noticiascaracol.com', 'washingtonpost.com', 'wsj.com', 'bloomberg.com',
        'who.int', 'esa.int', 'un.org', 'europa.eu', 'worldbank.org', 'imf.org', 'nature.com', 'science.org',
        'nationalgeographic.com', 'scientificamerican.com',
    ], explode(',', (string) env('SEARCH_EXTRA_TRUSTED_DOMAINS', ''))))))),

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
