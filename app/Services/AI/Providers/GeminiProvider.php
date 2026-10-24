<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\AI\GeminiModelCircuit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GeminiProvider implements AiProviderInterface
{
    private string $apiKey;

    /** @var list<string> */
    private array $apiKeys;

    private string $apiBaseUrl;

    private string $model;

    private float $temperature;

    private int $timeout;

    private string $promptVersion;

    private ?string $lastConnectionError = null;

    /** @var list<string> */
    private const FALLBACK_MODELS = ['gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'];

    public function __construct()
    {
        $this->apiKey = (string) config('ai.gemini.api_key', '');
        $configuredKeys = config('ai.gemini.api_keys', []);
        $this->apiKeys = array_values(array_unique(array_filter(
            is_array($configuredKeys) ? $configuredKeys : [$this->apiKey],
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        )));
        if ($this->apiKeys === [] && $this->apiKey !== '') {
            $this->apiKeys = [$this->apiKey];
        }
        $this->apiBaseUrl = rtrim((string) config('ai.gemini.api_base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $this->model = (string) config('ai.gemini.model', 'gemini-3.6-flash');
        $this->temperature = (float) config('ai.gemini.temperature', 0.7);
        $this->timeout = (int) config('ai.gemini.extraction_timeout', config('ai.timeout', 60));
        $this->promptVersion = (string) config('ai.prompt_version', '1.0.0');

        if ($this->apiKeys === []) {
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

        $lastRateLimit = null;
        $this->lastConnectionError = null;

        foreach ($this->apiKeys as $keyIndex => $apiKey) {
            foreach (GeminiModelCircuit::available($models) as $model) {
                try {
                    $result = $this->tryExtractWithModel($model, $apiKey, $title, $content, $description, $keyIndex);
                    if ($result !== null) {
                        return $result;
                    }
                } catch (AiProviderException $exception) {
                    if ($exception->getCode() !== 429) {
                        throw $exception;
                    }

                    $lastRateLimit = $exception;
                    Log::warning('Gemini key reached its rate limit; trying another configured key or model', [
                        'key_index' => $keyIndex + 1,
                        'model' => $model,
                    ]);

                    break;
                }
            }
        }

        if ($lastRateLimit instanceof AiProviderException) {
            throw $lastRateLimit;
        }

        if ($this->lastConnectionError !== null) {
            throw AiProviderException::connection($this->lastConnectionError);
        }

        throw AiProviderException::invalidResponse('All configured Gemini models are unavailable. Please try again later.');
    }

    private function tryExtractWithModel(string $model, string $apiKey, string $title, string $content, ?string $description, int $keyIndex): ?ExtractedClaimsResponse
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
        $thinkingLevel = trim((string) config('ai.gemini.extraction_thinking_level', ''));
        if ($thinkingLevel !== '') {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingLevel' => $thinkingLevel];
        }

        $url = "{$this->apiBaseUrl}/models/{$model}:generateContent?key={$apiKey}";

        // An overloaded model (503) or a stalled request is not worth retrying on the same model:
        // trip the circuit so this and the next analyses go straight to the next model.
        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout(10)
                ->acceptJson()
                ->post($url, $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Gemini request failed or timed out, trying fallback model', ['model' => $model]);
            GeminiModelCircuit::trip($model);
            $this->lastConnectionError = $exception->getMessage();

            return null;
        }

        if ($response->status() === 404) {
            Log::warning('Gemini model not available, trying fallback', ['model' => $model]);

            return null;
        }

        if ($response->status() === 503) {
            Log::warning('Gemini model overloaded, trying fallback', ['model' => $model]);
            GeminiModelCircuit::trip($model);

            return null;
        }

        try {
            return $this->handleResponse($response);
        } catch (AiProviderException $exception) {
            Log::error('Claim extraction failed', [
                'provider' => $this->getName(),
                'model' => $model,
                'key_index' => $keyIndex + 1,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }
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

        $maxClaims = max(1, (int) config('ai.extraction.max_claims', 10));

        return <<<PROMPT
You are an expert at extracting factual claims from news articles for verification purposes.

**Article to analyze:**

**Title:** {$title}

{$descriptionSection}**Content:**
{$content}

---

**Your task:**

1. Extract the factual claims of the article (at most {$maxClaims}, keeping the most important ones).
2. Identify key entities (people, places, organizations, etc.), at most 8.
3. List sources mentioned in the article.
4. Identify potential misinformation signals without concluding the article is fake.

**Important rules:**

- Do NOT invent claims. Every claim must be traceable to the article.
- Do NOT determine if claims are true or false. Only extract them.
- Separate independent claims. One claim per statement.
- Maintain proper names, dates, quantities, and locations exactly as written.
- Distinguish facts from opinions and predictions.
- For each claim, provide enough context to understand it independently, in one short sentence (max 25 words).
- Avoid duplicate claims.
- Calibrate importance: 0.9-1.0 only for the central thesis of the article (what the headline asserts), 0.5-0.7 for supporting facts, 0.1-0.4 for background or peripheral details.
- Set verifiable=false for claims that cannot be checked against external sources (opinions, predictions, private statements).

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
            $retryAfter = (int) ($response->header('Retry-After') ?: config('ai.rate_limit_backoff', 60));

            throw AiProviderException::rateLimit($retryAfter);
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
