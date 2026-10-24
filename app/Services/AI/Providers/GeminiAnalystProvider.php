<?php

namespace App\Services\AI\Providers;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystClaimEvaluation;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Exceptions\AiProviderException;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Models\ModelPrediction;
use App\Services\AI\GeminiModelCircuit;
use App\Services\Search\SourceDomainPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class GeminiAnalystProvider implements AnalystProviderInterface
{
    private string $apiKey;

    /** @var list<string> */
    private array $apiKeys;

    private string $apiBaseUrl;

    private string $model;

    private float $temperature;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey = config('ai.gemini.api_key') ?? '';
        $configuredKeys = config('ai.gemini.api_keys', []);
        $this->apiKeys = array_values(array_unique(array_filter(
            is_array($configuredKeys) ? $configuredKeys : [$this->apiKey],
            fn (mixed $key): bool => is_string($key) && trim($key) !== '',
        )));
        if ($this->apiKeys === [] && $this->apiKey !== '') {
            $this->apiKeys = [$this->apiKey];
        }
        $this->apiBaseUrl = config('ai.gemini.api_base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $this->model = config('ai.gemini.model', 'gemini-2.0-flash');
        $this->temperature = (float) config('ai.gemini.temperature', 0.2); // Lower temperature for analytical synthesis
        $this->timeout = (int) config('ai.gemini.analyst_timeout', config('ai.timeout', 60));
    }

    /** @var list<string> */
    private const FALLBACK_MODELS = [
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.1-flash-lite',
    ];

    public function synthesize(
        Article $article,
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        SourceAnalysisResult $sourceAnalysis,
    ): AnalystSynthesisResult {
        if ($this->apiKeys === []) {
            throw AiProviderException::configuration('GEMINI_API_KEY is not configured.');
        }

        Log::info('Starting qualitative synthesis via Gemini Analyst', [
            'provider' => $this->getName(),
            'model' => $this->model,
            'article_id' => $article->id,
            'claims_count' => $claims->count(),
            'verifications_count' => $verifications->count(),
            'evidences_count' => $evidences->count(),
        ]);

        $modelsToTry = array_unique(array_merge([$this->model], self::FALLBACK_MODELS));

        $lastRateLimit = null;
        foreach ($this->apiKeys as $keyIndex => $apiKey) {
            foreach (GeminiModelCircuit::available(array_values($modelsToTry)) as $attemptModel) {
                try {
                    $result = $this->trySynthesizeWithModel(
                        $attemptModel, $apiKey, $article, $claims, $verifications, $evidences, $prediction, $sourceAnalysis
                    );

                    if ($result !== null) {
                        return $result;
                    }
                } catch (AiProviderException $exception) {
                    if ($exception->getCode() !== 429) {
                        throw $exception;
                    }

                    $lastRateLimit = $exception;
                    Log::warning('Gemini analyst key reached its rate limit; trying the next configured key', [
                        'key_index' => $keyIndex + 1,
                        'model' => $attemptModel,
                    ]);
                    break;
                }
            }
        }

        if ($lastRateLimit instanceof AiProviderException) {
            throw $lastRateLimit;
        }

        throw AiProviderException::invalidResponse(
            'All Gemini models are currently unavailable. Please try again later.'
        );
    }

    /**
     * Attempt synthesis with a specific model, retrying on transient 503 errors.
     * Returns null if the model is permanently unavailable (404) or exhausted retries.
     */
    private function trySynthesizeWithModel(
        string $model,
        string $apiKey,
        Article $article,
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        SourceAnalysisResult $sourceAnalysis,
    ): ?AnalystSynthesisResult {
        try {
            $jsonSchema = $this->getJsonSchema();
            $prompt = $this->buildPrompt($article, $claims, $verifications, $evidences, $prediction, $sourceAnalysis);

            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => $this->temperature,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $jsonSchema,
                ],
            ];

            $url = "{$this->apiBaseUrl}/models/{$model}:generateContent?key={$apiKey}";

            $maxRetries = 3;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $response = Http::timeout($this->timeout)
                        ->connectTimeout(10)
                        ->withHeader('Content-Type', 'application/json')
                        ->post($url, $payload);
                } catch (ConnectionException $exception) {
                    Log::warning('Gemini analyst request failed or timed out, trying fallback model', ['model' => $model]);
                    GeminiModelCircuit::trip($model);

                    return null;
                }

                if ($response->status() === 404) {
                    Log::warning('Gemini analyst model not available, trying fallback', ['model' => $model]);

                    return null;
                }

                // status() === 0 means Guzzle/curl connection failure or timeout
                if ($response->status() === 0) {
                    Log::warning("Gemini analyst connection failure on {$model}, retrying", [
                        'attempt' => $attempt,
                        'model' => $model,
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep(min(3 ** $attempt, 30));

                        continue;
                    }

                    return null;
                }

                if ($response->status() === 503) {
                    Log::warning("Gemini analyst 503 on {$model}, trying fallback model", ['model' => $model]);
                    GeminiModelCircuit::trip($model);

                    return null;
                }

                return $this->handleResponse($response);
            }

            return null;
        } catch (AiProviderException $e) {
            Log::error('Gemini Analyst synthesis failed', [
                'provider' => $this->getName(),
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Unexpected error during Gemini Analyst synthesis', [
                'provider' => $this->getName(),
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw AiProviderException::connection("Gemini synthesis error: {$e->getMessage()}", 0, $e);
        }
    }

    public function getName(): string
    {
        return 'gemini_analyst';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param  Collection<int, Claim>  $claims
     * @param  Collection<int, ClaimVerification>  $verifications
     * @param  Collection<int, Evidence>  $evidences
     */
    private function buildPrompt(
        Article $article,
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        SourceAnalysisResult $sourceAnalysis,
    ): string {
        $claimsData = $claims->map(function (Claim $claim) use ($verifications): array {
            $domainPolicy = app(SourceDomainPolicy::class);
            $claimVerifications = $verifications->where('claim_id', $claim->id);
            $claimEvidence = $claimVerifications
                ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
                ->unique('url')
                ->reject(fn (Evidence $evidence): bool => $domainPolicy->isBlocked((string) $evidence->url))
                ->sortBy(fn (Evidence $evidence): int => $domainPolicy->isTrusted((string) $evidence->url) ? 0 : 1)
                ->take(5)
                ->map(fn (Evidence $evidence): array => [
                    'title' => $evidence->title,
                    'url' => $evidence->url,
                    'snippet' => Str::limit((string) $evidence->snippet, 400),
                    'domain' => $evidence->metadata['domain'] ?? parse_url((string) $evidence->url, PHP_URL_HOST),
                    'provider' => $evidence->metadata['provider'] ?? null,
                    'trusted_source' => $domainPolicy->isTrusted((string) $evidence->url),
                ])
                ->values()
                ->all();

            return [
                'id' => $claim->id,
                'text' => $claim->text,
                'importance' => $claim->importance,
                'verifiable' => $claim->verifiable,
                'type' => $claim->type,
                'context' => $claim->context,
                'verifications' => $claimVerifications->map(fn (ClaimVerification $verification): array => [
                    'provider' => $verification->provider,
                    'relation' => $verification->relation,
                    'status' => $verification->status,
                    'publisher' => $verification->publisher,
                    'rating' => $verification->normalized_rating,
                    'review_title' => $verification->fact_check_title,
                    'review_url' => $verification->fact_check_url,
                ])->values()->all(),
                'evidence' => $claimEvidence,
            ];
        })->values()->all();

        $bertData = $prediction ? [
            'prediction_label' => $prediction->prediction_label,
            'confidence_score' => $prediction->confidence_score,
            'probability' => $prediction->probability,
            'model_name' => $prediction->model_name,
        ] : null;

        $contextJson = json_encode([
            'article' => [
                'title' => $article->title,
                'description' => $article->description,
                'content' => $article->content ?? $article->text,
                'author' => $article->author_name ?? $article->author,
                'url' => $article->url,
            ],
            'source_analysis' => $sourceAnalysis->toArray(),
            'bert_prediction' => $bertData,
            'claims' => $claimsData,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
Eres un analista experto en verificación de información y periodismo de datos.
Analiza la credibilidad del siguiente artículo a partir EXCLUSIVAMENTE de los datos, claims, verificaciones de fact-checking y evidencias encontradas.

REGLAS CRÍTICAS:
1. NO inventes hechos, URLs o fuentes que no estén en el contexto proporcionado.
2. Cada claim tiene su propia lista de verificaciones y evidencias; no atribuyas la evidencia de otro claim.
3. Un resultado de búsqueda es una pista, no prueba de verdad. Un status 'no_match' no confirma ni refuta.
4. Usa 'supported' o 'refuted' solo cuando la evidencia vinculada lo sustente directamente; en caso contrario usa 'mixed' o 'unverified'.
5. Si fact-checkers discrepan, marca el claim como 'mixed' y explica el desacuerdo.
6. Evalúa todos los claims factual/verificables recibidos, conservando exactamente sus IDs; no inventes ni omitas claims.
7. Mantén baja la confianza si la evidencia es indirecta, antigua, escasa o no corresponde exactamente a la afirmación.
8. El BERT es una señal auxiliar de estilo y no demuestra por sí solo que un hecho sea verdadero o falso.
9. El resumen debe distinguir lo confirmado, refutado, mixto y no verificado; no conviertas falta de resultados en prueba de falsedad.
10. Detecta falacias, sesgos y señales de alerta como contexto, nunca como prueba factual.
11. El campo summary y key_findings los lee el público general: redactalos en lenguaje natural hablando de "la noticia" y "las afirmaciones" o hechos concretos, sin nombrar claims, IDs, BERT, el clasificador, fact-checking como proceso interno, búsquedas, proveedores, puntajes ni cobertura.
12. Devuelve un JSON estructurado con el formato requerido.

CONTEXTO DE ANÁLISIS:
{$contextJson}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'Veredicto final para el lector, en 3 a 5 frases de lenguaje claro y sin tecnicismos: qué se pudo confirmar, qué no y qué tan fiable es la noticia. No menciones claims, BERT, modelos, puntajes, cobertura, evidencias vinculadas, proveedores ni el funcionamiento del sistema.',
                ],
                'consistency_score' => [
                    'type' => 'number',
                    'description' => 'Puntuación de consistencia interna y concordancia con evidencias entre 0.0 y 1.0.',
                ],
                'suggested_verdict' => [
                    'type' => 'string',
                    'enum' => ['credible', 'probably_credible', 'inconclusive', 'probably_misleading', 'false'],
                    'description' => 'Veredicto sugerido según la evidencia.',
                ],
                'claim_evaluations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'claim_id' => [
                                'type' => 'integer',
                                'description' => 'ID del claim analizado.',
                            ],
                            'claim_text' => [
                                'type' => 'string',
                                'description' => 'Texto del claim.',
                            ],
                            'stance' => [
                                'type' => 'string',
                                'enum' => ['supported', 'refuted', 'mixed', 'unverified'],
                                'description' => 'Postura de la evidencia hacia el claim.',
                            ],
                            'reasoning' => [
                                'type' => 'string',
                                'description' => 'Razonamiento detallado que sustenta la postura.',
                            ],
                            'confidence' => [
                                'type' => 'number',
                                'description' => 'Nivel de confianza en la evaluación del claim (0.0 a 1.0).',
                            ],
                        ],
                        'required' => ['claim_id', 'claim_text', 'stance', 'reasoning', 'confidence'],
                    ],
                ],
                'fallacies_and_red_flags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Lista de falacias lógicas, titulares engañosos o señales de alerta detectadas.',
                ],
                'bias_indicators' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Indicadores de sesgo cognitivo, partidista o lenguaje hiperbólico.',
                ],
                'key_findings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Puntos clave para el lector, en lenguaje sencillo, sin mencionar claims, BERT, modelos ni detalles del sistema.',
                ],
            ],
            'required' => [
                'summary',
                'consistency_score',
                'suggested_verdict',
                'claim_evaluations',
                'fallacies_and_red_flags',
                'bias_indicators',
                'key_findings',
            ],
        ];
    }

    private function handleResponse(Response $response): AnalystSynthesisResult
    {
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?: config('ai.rate_limit_backoff', 60));
            throw AiProviderException::rateLimit($retryAfter);
        }

        if ($response->failed()) {
            throw AiProviderException::responseValidation("Gemini API error (Status {$response->status()}): {$response->body()}");
        }

        $data = $response->json();
        $rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! is_string($rawText) || trim($rawText) === '') {
            throw AiProviderException::responseValidation('Gemini returned an empty response.');
        }

        $decoded = json_decode($rawText, true);
        if (! is_array($decoded)) {
            throw AiProviderException::responseValidation('Failed to parse Gemini response as JSON: '.json_last_error_msg());
        }

        $claimEvaluations = [];
        foreach ($decoded['claim_evaluations'] ?? [] as $eval) {
            $claimEvaluations[] = new AnalystClaimEvaluation(
                claimId: (int) ($eval['claim_id'] ?? 0),
                claimText: (string) ($eval['claim_text'] ?? ''),
                stance: (string) ($eval['stance'] ?? 'unverified'),
                reasoning: (string) ($eval['reasoning'] ?? ''),
                confidence: (float) ($eval['confidence'] ?? 0.5),
            );
        }

        return new AnalystSynthesisResult(
            summary: (string) ($decoded['summary'] ?? 'Análisis completado.'),
            claimEvaluations: $claimEvaluations,
            consistencyScore: (float) ($decoded['consistency_score'] ?? 0.5),
            fallaciesAndRedFlags: (array) ($decoded['fallacies_and_red_flags'] ?? []),
            biasIndicators: (array) ($decoded['bias_indicators'] ?? []),
            keyFindings: (array) ($decoded['key_findings'] ?? []),
            suggestedVerdict: (string) ($decoded['suggested_verdict'] ?? 'inconclusive'),
            rawResponse: $decoded,
        );
    }
}
