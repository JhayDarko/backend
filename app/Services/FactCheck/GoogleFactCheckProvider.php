<?php

namespace App\Services\FactCheck;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
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
            $language = (string) ($claim['languageCode'] ?? '');
            $claimant = (string) ($claim['claimant'] ?? '');
            $claimDate = $claim['claimDate'] ?? null;
            $reviewDate = $claim['reviewDate'] ?? null;
            $publisher = $claim['publisher'] ?? [];
            $publisherName = (string) ($publisher['name'] ?? '');
            $publisherSite = (string) ($publisher['site'] ?? '');
            $reviews = $claim['claimReview'] ?? [];

            if (! is_array($reviews) || $reviews === []) {
                continue;
            }

            $review = $reviews[0] ?? [];
            $reviewUrl = (string) ($review['url'] ?? '');
            $reviewTitle = (string) ($review['title'] ?? '');
            $textualRating = (string) ($review['textualRating'] ?? '');
            $normalizedRating = FactCheckRatingNormalizer::normalize($textualRating);

            $normalized[] = [
                'provider' => $this->getName(),
                'query' => $cleanQuery,
                'claim_text' => $claimText,
                'claimant' => $claimant,
                'claim_date' => $claimDate,
                'review_date' => $reviewDate,
                'language' => $language,
                'publisher' => $publisherName,
                'publisher_site' => $publisherSite,
                'fact_check_url' => $reviewUrl,
                'fact_check_title' => $reviewTitle,
                'raw_rating' => $normalizedRating['raw_rating'],
                'normalized_rating' => $normalizedRating['normalized_rating'],
                'metadata' => [
                    'claim' => $claim,
                    'review' => $review,
                ],
            ];
        }

        return $normalized;
    }

    public function getName(): string
    {
        return 'google_fact_check';
    }
}
