<?php

namespace App\Services\FactCheck;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class GoogleFactCheckProvider implements FactCheckProviderInterface
{
    private string $apiKey;

    private string $apiBaseUrl;

    private int $timeout;

    private int $connectTimeout;

    public function __construct()
    {
        $this->apiKey = (string) config('fact_check.google.api_key', '');
        $this->apiBaseUrl = rtrim((string) config('fact_check.google.api_base_url', 'https://factchecktools.googleapis.com/v1alpha1/claims:search'), '/');
        $this->timeout = (int) config('fact_check.google.timeout', 20);
        $this->connectTimeout = (int) config('fact_check.google.connect_timeout', 5);
    }

    public function search(string $query): array
    {
        if (! (bool) config('fact_check.enabled', false)) {
            return [];
        }

        if ($this->apiKey === '') {
            throw AiProviderException::configuration('GOOGLE_FACT_CHECK_API_KEY is not configured.');
        }

        $cleanQuery = trim($query);

        if ($cleanQuery === '') {
            return [];
        }

        $url = $this->apiBaseUrl;

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->get($url, [
                    'query' => $cleanQuery,
                    'languageCode' => 'es',
                    'key' => $this->apiKey,
                ]);
        } catch (ConnectionException $exception) {
            throw AiProviderException::connection($exception->getMessage());
        }

        if ($response->status() === 429) {
            throw AiProviderException::rateLimit();
        }

        if ($response->status() === 401) {
            throw AiProviderException::configuration('Google Fact Check API authentication failed.');
        }

        if ($response->status() === 403) {
            throw AiProviderException::configuration('Google Fact Check API access denied.');
        }

        if ($response->status() === 404) {
            throw AiProviderException::httpError(404, 'Google Fact Check API endpoint not found.');
        }

        if ($response->status() === 500 || $response->status() === 502 || $response->status() === 503) {
            throw AiProviderException::httpError($response->status(), 'Google Fact Check API is temporarily unavailable.');
        }

        if ($response->failed()) {
            throw AiProviderException::httpError((int) $response->status(), $response->body() ?: 'Google Fact Check API request failed.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw AiProviderException::invalidResponse('Google Fact Check API returned invalid JSON.');
        }

        $claims = $payload['claims'] ?? [];

        if (! is_array($claims)) {
            return [];
        }

        $normalized = [];

        foreach ($claims as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $claimText = (string) ($claim['text'] ?? '');
            $claimant = (string) ($claim['claimant'] ?? '');
            $claimDate = $claim['claimDate'] ?? null;
            $reviews = $claim['claimReview'] ?? [];

            if (! is_array($reviews) || $reviews === []) {
                continue;
            }

            foreach ($reviews as $review) {
                if (! is_array($review)) {
                    continue;
                }

                $reviewPublisher = $review['publisher'] ?? [];
                $normalizedRating = FactCheckRatingNormalizer::normalize((string) ($review['textualRating'] ?? ''));

                $normalized[] = [
                    'provider' => $this->getName(),
                    'query' => $cleanQuery,
                    'claim_text' => $claimText,
                    'claimant' => $claimant,
                    'claim_date' => $claimDate,
                    'review_date' => $review['reviewDate'] ?? null,
                    'language' => (string) ($review['languageCode'] ?? ''),
                    'publisher' => (string) ($reviewPublisher['name'] ?? ''),
                    'publisher_site' => (string) ($reviewPublisher['site'] ?? ''),
                    'fact_check_url' => (string) ($review['url'] ?? ''),
                    'fact_check_title' => (string) ($review['title'] ?? ''),
                    'raw_rating' => $normalizedRating['raw_rating'],
                    'normalized_rating' => $normalizedRating['normalized_rating'],
                    'metadata' => [
                        'claim' => $claim,
                        'review' => $review,
                        'response_headers' => config('evaluation.active')
                            ? app(EvaluationSnapshotSanitizer::class)->rateLimitHeaders($response->headers())
                            : [],
                    ],
                ];
            }
        }

        return $normalized;
    }

    public function getName(): string
    {
        return 'google_fact_check';
    }
}
