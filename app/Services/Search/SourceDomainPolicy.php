<?php

namespace App\Services\Search;

use App\Services\Source\SourceAnalysisService;

/**
 * Decides which domains may be used as evidence: social networks and other
 * user-generated platforms are blocked, credible publishers are ranked first.
 */
final class SourceDomainPolicy
{
    private const TRUSTED_TIERS = ['fact_checker', 'academic_gov', 'mainstream_press'];

    public function __construct(
        private readonly SourceAnalysisService $sourceAnalysis,
    ) {}

    /**
     * @return list<string>
     */
    public function blockedDomains(): array
    {
        return array_values(array_filter((array) config('search.blocked_domains', []), 'is_string'));
    }

    public function isBlocked(string $urlOrHost): bool
    {
        $host = $this->host($urlOrHost);

        return $host !== '' && $this->matches($host, $this->blockedDomains());
    }

    public function isTrusted(string $urlOrHost): bool
    {
        $host = $this->host($urlOrHost);
        if ($host === '') {
            return false;
        }

        if ($this->matches($host, (array) config('search.trusted_domains', []))) {
            return true;
        }

        return in_array($this->sourceAnalysis->analyze(null, 'https://'.$host)->trustTier, self::TRUSTED_TIERS, true);
    }

    public function allows(string $urlOrHost): bool
    {
        if ($this->isBlocked($urlOrHost)) {
            return false;
        }

        return ! (bool) config('search.trusted_only', false) || $this->isTrusted($urlOrHost);
    }

    /**
     * Drops disallowed results and moves trusted ones first (stable order otherwise).
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    public function filterAndRank(array $results): array
    {
        $kept = [];

        foreach ($results as $position => $result) {
            $url = (string) ($result['url'] ?? '');
            if (! $this->allows($url)) {
                continue;
            }

            $kept[] = ['position' => $position, 'trusted' => $this->isTrusted($url), 'result' => $result];
        }

        usort($kept, fn (array $a, array $b): int => [! $a['trusted'], $a['position']] <=> [! $b['trusted'], $b['position']]);

        return array_map(fn (array $item): array => $item['result'], $kept);
    }

    private function host(string $urlOrHost): string
    {
        $value = strtolower(trim($urlOrHost));
        if ($value === '') {
            return '';
        }

        $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : parse_url('//'.$value, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', $host) ?? '' : '';
    }

    /**
     * @param  array<int, mixed>  $domains
     */
    private function matches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                return true;
            }
        }

        return false;
    }
}
