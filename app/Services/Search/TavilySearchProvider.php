<?php

namespace App\Services\Search;

use App\Contracts\Search\SearchProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

final class TavilySearchProvider implements SearchProviderInterface
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
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) config('search.providers.tavily.api_key', '');
        $this->apiBaseUrl = $apiBaseUrl !== '' ? $apiBaseUrl : (string) config('search.providers.tavily.api_base_url', 'https://api.tavily.com/search');
        $this->timeout = $timeout ?: (int) config('search.providers.tavily.timeout', 10);
        $this->connectTimeout = $connectTimeout ?: (int) config('search.providers.tavily.connect_timeout', 5);
        $this->maxResults = $maxResults ?: (int) config('search.providers.tavily.max_results', 10);
    }

    public function search(string $query, array $options = []): array
    {
        if (! (bool) config('search.enabled', false)) {
            return [];
        }

        if (! (bool) config('search.providers.tavily.enabled', false) || $this->apiKey === '') {
            throw AiProviderException::configuration('TAVILY_API_KEY is not configured.');
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
                ->post($this->apiBaseUrl, [
                    'api_key' => $this->apiKey,
                    'query' => $cleanQuery,
                    'max_results' => $limit,
                    'include_answer' => false,
                    'include_raw_content' => false,
                    'include_images' => false,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            throw AiProviderException::connection($exception->getMessage());
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimit();
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw AiProviderException::configuration('Tavily API authentication failed.');
        }

        if ($response->failed()) {
            throw AiProviderException::httpError((int) $response->status(), 'Tavily search request failed.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw AiProviderException::invalidResponse('Tavily returned invalid JSON.');
        }

        $results = $payload['results'] ?? [];
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
                'snippet' => (string) Arr::get($result, 'content', Arr::get($result, 'snippet', '')),
                'description' => (string) Arr::get($result, 'content', Arr::get($result, 'description', '')),
                'published_at' => Arr::get($result, 'published_date', Arr::get($result, 'publishedAt')),
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
        return 'tavily';
    }
}
