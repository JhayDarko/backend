<?php

namespace App\Services\Search;

use App\Contracts\Search\SearchProviderInterface;
use App\DTO\Search\SearchResult;
use Illuminate\Support\Arr;

final class SearchProviderManager implements SearchProviderInterface
{
    /**
     * @param  array<string, SearchProviderInterface>  $providers
     */
    public function __construct(
        protected array $providers = [],
    ) {
        $this->providers = $providers ?: [
            'tavily' => app(TavilySearchProvider::class),
            'brave' => app(BraveSearchProvider::class),
        ];
    }

    public function getName(): string
    {
        return (string) config('search.default', 'tavily');
    }

    /**
     * @return array<int, SearchResult>
     */
    public function search(string $query, array $options = []): array
    {
        if (! (bool) config('search.enabled', false)) {
            return [];
        }

        $primary = (string) config('search.default', 'tavily');
        $fallback = (string) config('search.fallback', 'brave');

        $ordered = array_values(array_unique([$primary, $fallback]));

        foreach ($ordered as $providerName) {
            if (! isset($this->providers[$providerName])) {
                continue;
            }

            $provider = $this->providers[$providerName];

            try {
                $payload = $provider->search($query, $options);
                $results = array_map(fn (array $item) => $this->normalizeResult($query, $providerName, $item), $payload);

                return array_values(array_filter($results, fn (?SearchResult $result) => $result !== null));
            } catch (\Throwable $exception) {
                if ($providerName === $fallback) {
                    throw $exception;
                }
            }
        }

        return [];
    }

    private function normalizeResult(string $query, string $provider, array $result): ?SearchResult
    {
        $title = trim((string) Arr::get($result, 'title', ''));
        $url = trim((string) Arr::get($result, 'url', ''));
        $snippet = trim((string) Arr::get($result, 'snippet', Arr::get($result, 'content', Arr::get($result, 'description', ''))));

        if ($title === '' && $url === '' && $snippet === '') {
            return null;
        }

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $domain = parse_url($url, PHP_URL_HOST);
        $publishedAt = Arr::get($result, 'published_at', Arr::get($result, 'publishedAt'));
        $sourceName = Arr::get($result, 'source_name', Arr::get($result, 'sourceName', $domain));
        $relevance = Arr::get($result, 'relevance', Arr::get($result, 'score'));

        return new SearchResult(
            query: $query,
            title: $title !== '' ? $title : 'Sin título',
            url: $url,
            snippet: $snippet !== '' ? $snippet : null,
            domain: $domain,
            publishedAt: is_string($publishedAt) ? $publishedAt : null,
            sourceName: is_string($sourceName) ? $sourceName : null,
            provider: $provider,
            relevance: is_numeric($relevance) ? (float) $relevance : null,
            metadata: $result,
        );
    }
}
