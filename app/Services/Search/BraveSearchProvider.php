<?php

namespace App\Services\Search;

use App\Contracts\Search\SearchProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

final class BraveSearchProvider implements SearchProviderInterface
{
    private string $apiKey;

    private string $apiBaseUrl;

    private int $timeout;

    private int $connectTimeout;

    private int $maxResults;

    public function __construct(
        string $apiKey = '',
        string $apiBaseUrl = '',
        int $timeout = 10,
        int $connectTimeout = 5,
        int $maxResults = 10,
    ) {
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) config('search.providers.brave.api_key', '');
        $this->apiBaseUrl = $apiBaseUrl !== '' ? $apiBaseUrl : (string) config('search.providers.brave.api_base_url', 'https://api.search.brave.com/res/v1/web/search');
        $this->timeout = $timeout ?: (int) config('search.providers.brave.timeout', 10);
        $this->connectTimeout = $connectTimeout ?: (int) config('search.providers.brave.connect_timeout', 5);
        $this->maxResults = $maxResults ?: (int) config('search.providers.brave.max_results', 10);
    }

    public function search(string $query, array $options = []): array
    {
        if (! (bool) config('search.enabled', false)) {
            return [];
        }

        if (! (bool) config('search.providers.brave.enabled', false) || $this->apiKey === '') {
            throw AiProviderException::configuration('BRAVE_API_KEY is not configured.');
        }

        $cleanQuery = trim($query);
        if ($cleanQuery === '') {
            return [];
        }

        $limit = (int) ($options['max_results'] ?? $this->maxResults);

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Subscription-Token' => $this->apiKey,
                ])
                ->get($this->apiBaseUrl, [
                    'q' => $cleanQuery,
                    'count' => $limit,
                    'search_lang' => 'es',
                    'country' => 'ES',
                ]);
        } catch (ConnectionException $exception) {
            throw AiProviderException::connection($exception->getMessage());
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimit();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw AiProviderException::configuration('Brave Search API authentication failed.');
        }

        if ($response->failed()) {
            throw AiProviderException::httpError((int) $response->status(), 'Brave search request failed.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw AiProviderException::invalidResponse('Brave returned invalid JSON.');
        }

        $results = $payload['web']['results'] ?? [];
        if (! is_array($results)) {
            return [];
        }

        $normalized = [];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $url = trim((string) Arr::get($result, 'url', ''));
            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $normalized[] = [
                'title' => (string) Arr::get($result, 'title', 'Sin título'),
                'url' => $url,
                'snippet' => (string) Arr::get($result, 'description', Arr::get($result, 'snippet', '')),
                'description' => (string) Arr::get($result, 'description', ''),
                'published_at' => Arr::get($result, 'published', Arr::get($result, 'publishedAt')),
                'source_name' => Arr::get($result, 'source', Arr::get($result, 'site')),
                'relevance' => Arr::get($result, 'score', Arr::get($result, 'relevance')),
                'provider' => $this->getName(),
                'metadata' => $result,
            ];
        }

        return $normalized;
    }

    public function getName(): string
    {
        return 'brave';
    }
}
