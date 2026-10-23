<?php

namespace App\Services\FactCheck;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use Illuminate\Support\Str;

class FactCheckService
{
    public function __construct(
        protected FactCheckProviderInterface $provider,
    ) {}

    /**
     * @return array{status: string, results: array<int, array<string, mixed>>}
     */
    public function verifyClaim(Claim $claim): array
    {
        if (config('evaluation.active') && config('evaluation.no_factcheck')) {
            return ['status' => 'disabled', 'results' => []];
        }

        $queries = $this->buildQueries($claim);
        $persisted = [];

        foreach ($queries as $query) {
            $results = $this->provider->search($query);
            $hasUsableRating = false;

            foreach ($results as $result) {
                if (config('evaluation.active')) {
                    $result = app(EvaluationSnapshotSanitizer::class)->sanitize($result);
                }

                if (config('evaluation.active')
                    && config('evaluation.mode') === 'blind'
                    && $this->isExcludedResult($result)) {
                    continue;
                }

                $persisted[] = $this->persistResult($claim, $result);
                $normalizedRating = strtolower((string) ($result['normalized_rating'] ?? 'unknown'));
                if ($normalizedRating === '' || $normalizedRating === 'unknown') {
                    $normalizedRating = FactCheckRatingNormalizer::normalize((string) ($result['raw_rating'] ?? ''))['normalized_rating'];
                }
                $hasUsableRating = $hasUsableRating || $normalizedRating !== 'unknown';
            }

            if ($hasUsableRating) {
                break;
            }
        }

        if ($persisted === []) {
            $this->persistNoMatch($claim, $queries);
        }

        return [
            'status' => $persisted === [] ? 'no_match' : 'matched',
            'results' => $persisted,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function isExcludedResult(array $result): bool
    {
        $domains = array_values(array_filter(
            array_map(fn (mixed $domain): string => strtolower(trim((string) $domain)), (array) config('evaluation.excluded_domains', [])),
            fn (string $domain): bool => $domain !== '',
        ));
        $publisherSite = trim((string) ($result['publisher_site'] ?? ''));
        $publisherHost = parse_url($publisherSite, PHP_URL_HOST);
        if (! is_string($publisherHost) && $publisherSite !== '') {
            $publisherHost = parse_url('//'.$publisherSite, PHP_URL_HOST);
        }
        $hosts = [
            strtolower(is_string($publisherHost) ? $publisherHost : ''),
            strtolower((string) parse_url((string) ($result['fact_check_url'] ?? ''), PHP_URL_HOST)),
        ];

        foreach ($hosts as $host) {
            foreach ($domains as $domain) {
                if ($host !== '' && ($host === $domain || str_ends_with($host, '.'.$domain))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function buildQueries(Claim $claim): array
    {
        $base = trim((string) $claim->text);

        if ($base === '') {
            return ['fact check'];
        }

        $exact = Str::limit($base, 180, '');
        $stopWords = array_flip([
            'a', 'al', 'algo', 'como', 'con', 'contra', 'cual', 'cuando', 'de', 'del', 'desde', 'donde',
            'el', 'ella', 'ellas', 'ellos', 'en', 'entre', 'era', 'es', 'esa', 'ese', 'esta', 'este',
            'fue', 'ha', 'hace', 'la', 'las', 'lo', 'los', 'más', 'menos', 'muy', 'no', 'o', 'para',
            'pero', 'por', 'porque', 'que', 'se', 'según', 'sin', 'sobre', 'su', 'sus', 'también',
            'un', 'una', 'uno', 'unos', 'unas', 'y', 'ya',
        ]);
        $tokens = preg_split('/[^\p{L}\p{N}%]+/u', mb_strtolower($base)) ?: [];
        $keywords = [];

        foreach ($tokens as $token) {
            if ($token === '' || (mb_strlen($token) < 3 && ! is_numeric($token)) || isset($stopWords[$token])) {
                continue;
            }
            $keywords[$token] = true;
        }

        $compact = implode(' ', array_slice(array_keys($keywords), 0, 10));

        return array_values(array_unique(array_filter([$exact, $compact], fn (string $query): bool => $query !== '')));
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

    private function persistNoMatch(Claim $claim, array $queries): void
    {
        $providerName = $this->provider->getName();
        $fingerprint = md5(implode('|', [(string) $claim->id, $providerName, 'no_match', implode('|', $queries)]));

        ClaimVerification::query()->firstOrCreate([
            'fingerprint' => $fingerprint,
        ], [
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'status' => 'no_match',
            'provider' => $providerName,
            'query_used' => implode(' | ', $queries),
            'normalized_rating' => 'unknown',
            'metadata' => ['result_count' => 0, 'queries_attempted' => $queries],
            'confidence' => 0.0,
            'relevance_score' => 0.0,
        ]);
    }
}
