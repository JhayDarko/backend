<?php

namespace App\Services\Search;

use App\Contracts\Search\SearchProviderInterface;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use Illuminate\Support\Str;

final class EvidenceSearchService
{
    protected SourceDomainPolicy $domainPolicy;

    public function __construct(
        protected SearchProviderInterface $provider,
        ?SourceDomainPolicy $domainPolicy = null,
    ) {
        $this->domainPolicy = $domainPolicy ?? app(SourceDomainPolicy::class);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(Claim $claim): array
    {
        if (! $claim->verifiable) {
            return [];
        }

        $query = $this->buildQuery($claim);
        $results = $this->provider->search($query, [
            'max_results' => max(1, (int) config('search.fetch_results', 15)),
            'exclude_domains' => $this->domainPolicy->blockedDomains(),
        ]);

        $normalized = [];

        foreach ($results as $result) {
            if ($result instanceof \App\DTO\Search\SearchResult) {
                $url = trim($result->url);
                if ($url === '') {
                    continue;
                }

                $canonicalUrl = $this->canonicalizeUrl($url);
                $normalized[] = [
                    'query' => $query,
                    'provider' => $result->provider,
                    'title' => $result->title !== '' ? $result->title : 'Sin título',
                    'url' => $canonicalUrl,
                    'snippet' => $result->snippet ?? '',
                    'domain' => $result->domain ?: (string) (parse_url($canonicalUrl, PHP_URL_HOST) ?? ''),
                    'published_at' => $result->publishedAt,
                    'source_name' => $result->sourceName,
                    'relevance' => $result->relevance,
                    'metadata' => $result->metadata,
                ];
                continue;
            }

            if (is_array($result)) {
                $url = trim((string) ($result['url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                $canonicalUrl = $this->canonicalizeUrl($url);
                $normalized[] = [
                    'query' => $query,
                    'provider' => $result['provider'] ?? $this->provider->getName(),
                    'title' => (string) ($result['title'] ?? 'Sin título'),
                    'url' => $canonicalUrl,
                    'snippet' => (string) ($result['snippet'] ?? ''),
                    'domain' => (string) ($result['domain'] ?? parse_url($canonicalUrl, PHP_URL_HOST) ?? ''),
                    'published_at' => $result['published_at'] ?? $result['publishedAt'] ?? null,
                    'source_name' => $result['source_name'] ?? $result['sourceName'] ?? null,
                    'relevance' => $result['relevance'] ?? null,
                    'metadata' => $result['metadata'] ?? $result,
                ];
            }
        }

        $credible = $this->domainPolicy->filterAndRank($this->deduplicateResults($normalized));

        return array_slice($credible, 0, max(1, (int) config('search.evidence_per_claim', 8)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function persistForClaim(Claim $claim): array
    {
        $results = $this->search($claim);
        if ($results === []) {
            return [];
        }

        $persisted = [];

        foreach ($results as $result) {
            $url = (string) ($result['url'] ?? '');
            $provider = (string) ($result['provider'] ?? $this->provider->getName());
            $fingerprint = md5(implode('|', [
                (string) $claim->id,
                $provider,
                $url,
            ]));

            $verification = ClaimVerification::query()->createOrFirst([
                'claim_id' => $claim->id,
                'provider' => $provider,
                'fingerprint' => $fingerprint,
            ], [
                'relation' => 'search_evidence',
                'status' => 'matched',
                'query_used' => (string) ($result['query'] ?? $this->buildQuery($claim)),
                'publisher' => (string) ($result['source_name'] ?? ''),
                'fact_check_url' => $url,
                'fact_check_title' => (string) ($result['title'] ?? ''),
                'raw_rating' => '',
                'normalized_rating' => 'unknown',
                'language' => 'es',
                'metadata' => $result['metadata'] ?? $result,
                'confidence' => 0.0,
                'relevance_score' => is_numeric($result['relevance'] ?? null) ? (float) $result['relevance'] : 0.0,
                'fingerprint' => $fingerprint,
            ]);

            $evidence = Evidence::query()->createOrFirst([
                'url' => $url,
            ], [
                'title' => (string) ($result['title'] ?? ''),
                'snippet' => (string) ($result['snippet'] ?? ''),
                'content' => (string) ($result['snippet'] ?? ''),
                'published_at' => $result['published_at'] ?? null,
                'retrieved_at' => now(),
                'metadata' => [
                    'provider' => $provider,
                    'source_name' => $result['source_name'] ?? null,
                    'domain' => $result['domain'] ?? null,
                    'relevance' => $result['relevance'] ?? null,
                    'trusted' => $this->domainPolicy->isTrusted($url),
                ],
            ]);

            $verification->evidences()->syncWithoutDetaching([$evidence->id]);

            $persisted[] = [
                'claim_verification_id' => $verification->id,
                'evidence_id' => $evidence->id,
                'provider' => $provider,
                'url' => $url,
                'title' => $result['title'] ?? null,
            ];
        }

        return $persisted;
    }

    public function buildQuery(Claim $claim): string
    {
        $text = trim((string) $claim->text);
        if ($text === '') {
            return 'fact check';
        }

        $keywords = preg_split('/\s+/', $text);
        if (! is_array($keywords)) {
            return $text;
        }

        $filtered = array_values(array_filter($keywords, fn ($item) => strlen(trim((string) $item)) > 2));
        $query = implode(' ', array_slice($filtered, 0, 12));

        return $query !== '' ? $query : $text;
    }

    private function canonicalizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $trimmed;
        }

        $parsed = parse_url($trimmed);
        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return $trimmed;
        }

        $scheme = strtolower((string) $parsed['scheme']);
        $host = strtolower((string) $parsed['host']);
        $path = $parsed['path'] ?? '/';

        $cleanPath = preg_replace('#/+$#', '', $path);
        $query = $parsed['query'] ?? '';
        parse_str($query, $queryParts);
        unset($queryParts['utm_source'], $queryParts['utm_medium'], $queryParts['utm_campaign'], $queryParts['utm_term'], $queryParts['utm_content']);

        $canonicalQuery = http_build_query($queryParts, '', '&', PHP_QUERY_RFC3986);
        $base = $scheme.'://'.$host.$cleanPath;

        return $canonicalQuery !== '' ? $base.'?'.$canonicalQuery : $base;
    }

    /**
     * @param  array<int, array<string, mixed>> $results
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateResults(array $results): array
    {
        $unique = [];
        $seen = [];

        foreach ($results as $result) {
            $key = md5((string) ($result['url'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $result;
        }

        return $unique;
    }
}
