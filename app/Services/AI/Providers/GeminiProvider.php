<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GeminiProvider implements AiProviderInterface
{
    private string $apiKey;

    private string $apiBaseUrl;

    private string $model;

    private float $temperature;

    private int $timeout;

    private string $promptVersion;

    /** @var list<string> */
    private const FALLBACK_MODELS = ['gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'];

    public function __construct()
    {
        $this->apiKey = (string) config('ai.gemini.api_key', '');
        $this->apiBaseUrl = rtrim((string) config('ai.gemini.api_base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->model = (string) config('ai.gemini.model', 'gemini-3.6-flash');
        $this->temperature = (float) config('ai.gemini.temperature', 0.7);
        $this->timeout = (int) config('ai.timeout', 60);
        $this->promptVersion = (string) config('ai.prompt_version', '1.0.0');

        if ($this->apiKey === '') {
            throw AiProviderException::configuration('GEMINI_API_KEY is not configured.');
        }
    }

    public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
    {
        Log::info('Starting claim extraction via Gemini', [
            'provider' => $this->getName(),
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
        ]);

        $fallbackModels = config('ai.gemini.fallback_models', self::FALLBACK_MODELS);
        $fallbackModels = is_array($fallbackModels) ? $fallbackModels : self::FALLBACK_MODELS;
        $models = array_values(array_unique(array_filter([
            $this->model,
            ...$fallbackModels,
        ], fn (mixed $model): bool => is_string($model) && $model !== '')));

        foreach ($models as $model) {
            $result = $this->tryExtractWithModel($model, $title, $content, $description);
            if ($result !== null) {
                return $result;
            }
        }

        throw AiProviderException::invalidResponse('All configured Gemini models are unavailable. Please try again later.');
    }

    private function tryExtractWithModel(string $model, string $title, string $content, ?string $description): ?ExtractedClaimsResponse
    {
        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $this->buildPrompt($title, $content, $description)]],
            ]],
            'generationConfig' => [
                'temperature' => $this->temperature,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->getJsonSchema(),
            ],
        ];
        $url = "{$this->apiBaseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->connectTimeout(10)
                    ->acceptJson()
                    ->post($url, $payload);
            } catch (ConnectionException $exception) {
                throw AiProviderException::connection($exception->getMessage());
            }

            if ($response->status() === 404) {
                Log::warning('Gemini model not available, trying fallback', ['model' => $model]);

                return null;
            }

            if ($response->status() === 503 && $attempt < 3) {
                sleep($attempt);

                continue;
            }

            if ($response->status() === 503) {
                Log::warning('Gemini model remained unavailable after retries, trying fallback', ['model' => $model]);

                return null;
            }

            try {
                return $this->handleResponse($response);
            } catch (AiProviderException $exception) {
                Log::error('Claim extraction failed', [
                    'provider' => $this->getName(),
                    'model' => $model,
                    'error' => $exception->getMessage(),
                ]);
                throw $exception;
            }
        }

        return null;
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function buildPrompt(string $title, string $content, ?string $description = null): string
    {
        $descriptionSection = $description !== null && trim($description) !== ''
            ? "**Description:** {$description}\n\n"
            : '';

        return <<<PROMPT
You are an expert at extracting factual claims from news articles for verification purposes.

**Article to analyze:**

**Title:** {$title}

{$descriptionSection}**Content:**
{$content}

---

**Your task:**

1. Extract all factual claims from the article.
2. Identify key entities (people, places, organizations, etc.).
3. List sources mentioned in the article.
4. Identify potential misinformation signals without concluding the article is fake.

**Important rules:**

- Do NOT invent claims. Every claim must be traceable to the article.
- Do NOT determine if claims are true or false. Only extract them.
- Separate independent claims. One claim per statement.
- Maintain proper names, dates, quantities, and locations exactly as written.
- Distinguish facts from opinions and predictions.
- For each claim, provide enough context to understand it independently.
- Avoid duplicate claims.

Return JSON matching the supplied schema. Each claim must have importance between 0 and 1.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function getJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'importance' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            'verifiable' => ['type' => 'boolean'],
                            'type' => ['type' => 'string', 'enum' => ['factual', 'statistical', 'temporal', 'causal', 'attribution', 'quote', 'prediction', 'opinion']],
                            'context' => ['type' => 'string'],
                        ],
                        'required' => ['text', 'importance', 'verifiable', 'type', 'context'],
                    ],
                ],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string'], 'type' => ['type' => 'string']],
                        'required' => ['name', 'type'],
                    ],
                ],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['name' => ['type' => 'string'], 'url' => ['type' => 'string']],
                        'required' => ['name'],
                    ],
                ],
                'red_flags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['summary', 'claims', 'entities', 'sources', 'red_flags'],
        ];
    }

    private function handleResponse(Response $response): ExtractedClaimsResponse
    {
        if ($response->status() === 429) {
            throw AiProviderException::rateLimit((int) $response->header('Retry-After'));
        }

        if (in_array($response->status(), [408, 504], true)) {
            throw AiProviderException::timeout($this->timeout);
        }

        if (! $response->successful()) {
            throw AiProviderException::httpError(
                $response->status(),
                (string) ($response->json('error.message') ?? 'Unexpected response status'),
            );
        }

        $jsonText = $response->json('candidates.0.content.parts.0.text');
        if (! is_string($jsonText) || trim($jsonText) === '') {
            throw AiProviderException::invalidResponse('Missing content in response');
        }

        try {
            $data = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw AiProviderException::invalidResponse("Failed to parse JSON response: {$exception->getMessage()}");
        }

        if (! is_array($data)) {
            throw AiProviderException::invalidResponse('Gemini response JSON must be an object.');
        }

        return $this->validateAndCreateResponse($data);
    }

    /** @param array<string, mixed> $data */
    private function validateAndCreateResponse(array $data): ExtractedClaimsResponse
    {
        $errors = [];

        if (! isset($data['summary']) || ! is_string($data['summary'])) {
            $errors[] = 'Missing or invalid summary';
        }

        if (! isset($data['claims']) || ! is_array($data['claims'])) {
            $errors[] = 'Missing or invalid claims array';
        } else {
            foreach ($data['claims'] as $index => $claim) {
                if (! is_array($claim)) {
                    $errors[] = "Claim {$index} is not an object";

                    continue;
                }

                if (! isset($claim['text']) || ! is_string($claim['text']) || trim($claim['text']) === '') {
                    $errors[] = "Claim {$index} missing text";
                }
                if (! isset($claim['importance']) || ! is_numeric($claim['importance'])) {
                    $errors[] = "Claim {$index} missing or invalid importance";
                } elseif ((float) $claim['importance'] < 0 || (float) $claim['importance'] > 1) {
                    $errors[] = "Claim {$index} importance out of range (0-1)";
                }
                if (! isset($claim['verifiable']) || ! is_bool($claim['verifiable'])) {
                    $errors[] = "Claim {$index} missing or invalid verifiable";
                }

                $type = $claim['type'] ?? null;
                $validTypes = ['factual', 'statistical', 'temporal', 'causal', 'attribution', 'quote', 'prediction', 'opinion'];
                if (! is_string($type) || ! in_array($type, $validTypes, true)) {
                    $errors[] = "Claim {$index} invalid type: ".($type ?? 'missing');
                }
                if (! isset($claim['context']) || ! is_string($claim['context']) || trim($claim['context']) === '') {
                    $errors[] = "Claim {$index} missing context";
                }
            }
        }

        foreach (['entities', 'sources', 'red_flags'] as $field) {
            if (! isset($data[$field]) || ! is_array($data[$field])) {
                $errors[] = "Missing or invalid {$field} array";
            }
        }

        if ($errors !== []) {
            throw AiProviderException::validation($errors);
        }

        Log::info('Claim extraction completed successfully', [
            'provider' => $this->getName(),
            'claims_count' => count($data['claims']),
            'entities_count' => count($data['entities']),
            'sources_count' => count($data['sources']),
            'red_flags_count' => count($data['red_flags']),
        ]);

        return ExtractedClaimsResponse::from($data);
    }

    private function maskUrl(string $url): string
    {
        return preg_replace('/key=[^&]*/', 'key=***', $url) ?? $url;
    }
}
