<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class OpenAiProvider implements AiProviderInterface
{
    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private float $temperature;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = (string) config('ai.openai.api_key', '');
        $this->baseUrl = rtrim((string) config('ai.openai.api_base_url', 'https://api.openai.com/v1'), '/');
        $this->model = (string) config('ai.openai.model', 'gpt-4o-mini');
        $this->temperature = (float) config('ai.openai.temperature', 0.2);
        $this->timeout = (int) config('ai.openai.timeout', config('ai.timeout', 120));

    }

    public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
    {
        if ($this->apiKey === '') {
            throw AiProviderException::configuration('OPENAI_API_KEY is not configured.');
        }

        $descriptionSection = $description !== null && trim($description) !== ''
            ? "Description: {$description}\n\n"
            : '';

        $prompt = <<<PROMPT
You are an expert at extracting factual claims from Spanish news articles for a verification pipeline.

Title: {$title}

{$descriptionSection}Content:
{$content}

Extract only claims explicitly stated in the article. Do not decide whether the article is true or false.
Return a JSON object with exactly these keys: summary, claims, entities, sources, red_flags.
Each claim must include text, importance (0 to 1), verifiable (boolean), type, and context.
Allowed claim types: factual, statistical, temporal, causal, attribution, quote, prediction, opinion.
Do not invent claims, sources, verdicts, or credibility scores.
PROMPT;

        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You return only valid JSON matching the requested structure.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ];

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->withToken($this->apiKey)
                ->post($this->baseUrl.'/chat/completions', $payload);
        } catch (ConnectionException $exception) {
            throw AiProviderException::connection($exception->getMessage());
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: config('ai.rate_limit_backoff', 60));
            $providerMessage = (string) ($response->json('error.message') ?? 'OpenAI quota or rate limit exceeded.');
            $providerCode = strtolower((string) ($response->json('error.code') ?? ''));
            $providerType = strtolower((string) ($response->json('error.type') ?? ''));
            $normalizedMessage = strtolower($providerMessage);

            if (in_array($providerCode, ['insufficient_quota', 'billing_hard_limit_reached'], true)
                || str_contains($providerType, 'insufficient_quota')
                || str_contains($normalizedMessage, 'no credits remaining')
                || str_contains($normalizedMessage, 'insufficient quota')) {
                throw AiProviderException::quotaExhausted('OpenAI', $providerMessage);
            }

            throw new AiProviderException(
                "OpenAI rate limit exceeded (retry after {$retryAfter}s): {$providerMessage}",
                429,
            );
        }

        if (in_array($response->status(), [408, 504], true)) {
            throw AiProviderException::timeout($this->timeout);
        }

        if ($response->failed()) {
            throw AiProviderException::httpError(
                $response->status(),
                (string) ($response->json('error.message') ?? 'Unexpected OpenAI response status'),
            );
        }

        return $this->parseResponse($response);
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function parseResponse(Response $response): ExtractedClaimsResponse
    {
        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            throw AiProviderException::invalidResponse('Missing content in OpenAI response.');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw AiProviderException::invalidResponse("Failed to parse OpenAI JSON response: {$exception->getMessage()}");
        }

        if (! is_array($data)) {
            throw AiProviderException::invalidResponse('OpenAI response JSON must be an object.');
        }

        foreach (['summary', 'claims', 'entities', 'sources', 'red_flags'] as $field) {
            if (! array_key_exists($field, $data) || ! is_array($data[$field]) && $field !== 'summary') {
                throw AiProviderException::validation(["Missing or invalid {$field} in OpenAI response."]);
            }
        }

        if (! is_string($data['summary'])) {
            throw AiProviderException::validation(['OpenAI summary must be a string.']);
        }

        Log::info('Claim extraction completed successfully via OpenAI fallback', [
            'provider' => $this->getName(),
            'model' => $this->model,
            'claims_count' => count($data['claims']),
        ]);

        return ExtractedClaimsResponse::from($data);
    }
}
