<?php

namespace App\Services\FactCheck;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use Illuminate\Support\Str;

class FactCheckService
{
    public function __construct(
        protected FactCheckProviderInterface $provider,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function verifyClaim(Claim $claim): array
    {
        $query = $this->buildQuery($claim);
        $results = $this->provider->search($query);

        if ($results === []) {
            $this->persistNoMatch($claim, $query);

            return [
                'status' => 'no_match',
                'results' => [],
            ];
        }

        $persisted = [];

        foreach ($results as $result) {
            $persisted[] = $this->persistResult($claim, $result);
        }

        return [
            'status' => 'matched',
            'results' => $persisted,
        ];
    }

    private function buildQuery(Claim $claim): string
    {
        $base = trim((string) $claim->text);

        if ($base === '') {
            return 'fact check';
        }

        return Str::limit($base, 180, '');
    }

    private function persistResult(Claim $claim, array $result): array
    {
        $providerName = (string) ($result['provider'] ?? $this->provider->getName());
        $factCheckUrl = (string) ($result['fact_check_url'] ?? '');
        $publisherName = (string) ($result['publisher'] ?? '');
        $reviewDate = $result['review_date'] ?? null;
        $fingerprint = md5(implode('|', [
            (string) $claim->id,
            $providerName,
            $factCheckUrl,
            $publisherName,
            (string) ($reviewDate ?? ''),
        ]));

        $verification = ClaimVerification::query()->firstOrCreate([
            'fingerprint' => $fingerprint,
        ], [
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'status' => 'matched',
            'provider' => $providerName,
            'query_used' => (string) ($result['query'] ?? $this->buildQuery($claim)),
            'publisher' => $publisherName,
            'publisher_site' => (string) ($result['publisher_site'] ?? ''),
            'fact_check_url' => $factCheckUrl,
            'fact_check_title' => (string) ($result['fact_check_title'] ?? ''),
            'raw_rating' => (string) ($result['raw_rating'] ?? ''),
            'normalized_rating' => (string) ($result['normalized_rating'] ?? 'unknown'),
            'review_date' => $reviewDate ? now()->parse($reviewDate) : null,
            'language' => (string) ($result['language'] ?? ''),
            'metadata' => $result['metadata'] ?? [],
            'confidence' => 0.0,
            'relevance_score' => 0.0,
            'fingerprint' => $fingerprint,
        ]);

        $evidenceUrl = (string) ($result['fact_check_url'] ?? '');

        if ($evidenceUrl !== '') {
            $evidence = Evidence::query()->firstOrCreate([
                'url' => $evidenceUrl,
            ], [
                'title' => (string) ($result['fact_check_title'] ?? ''),
                'snippet' => (string) ($result['fact_check_title'] ?? ''),
                'content' => (string) ($result['fact_check_title'] ?? ''),
                'published_at' => $reviewDate ? now()->parse($reviewDate) : null,
                'retrieved_at' => now(),
                'metadata' => [
                    'provider' => $providerName,
                    'publisher' => $publisherName,
                    'publisher_site' => (string) ($result['publisher_site'] ?? ''),
                    'language' => (string) ($result['language'] ?? ''),
                ],
            ]);

            $verification->evidences()->syncWithoutDetaching([$evidence->id]);
        }

        return [
            'claim_verification_id' => $verification->id,
            'provider' => $providerName,
            'status' => 'matched',
            'raw_rating' => $verification->raw_rating,
            'normalized_rating' => $verification->normalized_rating,
        ];
    }

    private function persistNoMatch(Claim $claim, string $query): void
    {
        $providerName = $this->provider->getName();
        $fingerprint = md5(implode('|', [(string) $claim->id, $providerName, 'no_match', $query]));

        ClaimVerification::query()->firstOrCreate([
            'fingerprint' => $fingerprint,
        ], [
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'status' => 'no_match',
            'provider' => $providerName,
            'query_used' => $query,
            'normalized_rating' => 'unknown',
            'metadata' => ['result_count' => 0],
            'confidence' => 0.0,
            'relevance_score' => 0.0,
        ]);
    }
}
