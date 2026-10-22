<?php

namespace App\Services\Verification;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\Verification\DTO\ClaimExtractionResult;
use Illuminate\Support\Facades\DB;

final class ClaimExtractionService
{
    private const ALLOWED_CLAIM_TYPES = [
        'factual',
        'statistical',
        'temporal',
        'causal',
        'attribution',
        'quote',
        'prediction',
        'opinion',
    ];

    public function extract(Article $article): ClaimExtractionResult
    {
        $this->assertArticleIsUsable($article);

        $promptVersion = (string) config('ai.prompt_version', '1.0.0');
        $prompt = $this->buildPrompt($article, $promptVersion);
        $schema = $this->buildStructuredOutputSchema();

        $provider = app()->bound(AiProviderInterface::class)
            ? app(AiProviderInterface::class)
            : new GeminiProvider;

        if (! $provider instanceof AiProviderInterface) {
            throw AiProviderException::configuration('No AI provider implementation is registered.');
        }

        $response = $provider->extractClaims(
            (string) $article->title,
            (string) $article->content,
            $article->description !== null ? (string) $article->description : null,
        );

        $this->assertStructuredOutputIsAllowed($response, $prompt, $schema);

        return $this->normalizeAndPrepareResponse($response, $promptVersion);
    }

    public function extractAndPersist(Article $article, Analysis $analysis): ClaimExtractionResult
    {
        $result = $this->extract($article);

        $this->persistClaims($analysis, $result);

        return $result;
    }

    /**
     * @param  array<int, array{text: string, importance: float, verifiable: bool, type: string, context: string}>  $claims
     */
    public function persistClaims(Analysis $analysis, ClaimExtractionResult $result): int
    {
        $this->validatePersistableClaims($result->claims);

        return DB::transaction(function () use ($analysis, $result) {
            $persisted = 0;

            foreach ($result->claims as $claimData) {
                $normalized = $this->normalizeClaimPayload($claimData);
                $fingerprint = $this->calculateFingerprint(
                    $normalized['text'],
                    $normalized['type'],
                    $normalized['context'],
                );

                $existing = Claim::query()
                    ->where('analysis_id', $analysis->getKey())
                    ->where('fingerprint', $fingerprint)
                    ->first();

                if ($existing) {
                    continue;
                }

                Claim::query()->create([
                    'analysis_id' => $analysis->getKey(),
                    'text' => $normalized['text'],
                    'type' => $normalized['type'],
                    'importance' => $normalized['importance'],
                    'verifiable' => $normalized['verifiable'],
                    'context' => $normalized['context'],
                    'fingerprint' => $fingerprint,
                    'metadata' => [
                        'source' => 'gemini',
                        'summary' => $result->summary,
                    ],
                ]);

                $persisted++;
            }

            return $persisted;
        }, 3);
    }

    public function buildPrompt(Article $article, ?string $promptVersion = null): string
    {
        $version = $promptVersion ?? (string) config('ai.prompt_version', '1.0.0');
        $title = $this->cleanText((string) ($article->title ?? ''));
        $description = $this->cleanText((string) ($article->description ?? ''));
        $content = $this->cleanText((string) ($article->content ?? ''));

        $descriptionSection = $description !== ''
            ? "**Description:** {$description}\n\n"
            : '';

        return <<<PROMPT
You are an expert extractor of claims from news articles for a verification pipeline.

Prompt version: {$version}

Article title: {$title}

{$descriptionSection}Article content:
{$content}

Return only a JSON object with exactly these keys:
- summary
- claims
- entities
- sources
- red_flags

Rules:
1. Extract only claims explicitly supported by the article.
2. Do not invent claims.
3. Do not return a verdict or boolean verdict for the article.
4. Do not return any field named verdict.
5. Do not return any field named credibility_score.
6. Do not call external services, search the web, or use Google Fact Check.
7. Do not use BERT or any external model for verification.
8. Only classify claim types using: factual, statistical, temporal, causal, attribution, quote, prediction, opinion.
9. For each claim include: text, importance, verifiable, type, context.
10. Keep claims normalized, de-duplicated, and grounded in the article text.
11. No unnecessary keys. No verdict. No credibility_score.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStructuredOutputSchema(): array
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
                            'type' => ['type' => 'string', 'enum' => self::ALLOWED_CLAIM_TYPES],
                            'context' => ['type' => 'string'],
                        ],
                        'required' => ['text', 'importance', 'verifiable', 'type', 'context'],
                    ],
                ],
                'entities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'type'],
                    ],
                ],
                'sources' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                        'required' => ['name'],
                    ],
                ],
                'red_flags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['summary', 'claims', 'entities', 'sources', 'red_flags'],
        ];
    }

    private function normalizeAndPrepareResponse(
        ExtractedClaimsResponse $response,
        string $promptVersion,
    ): ClaimExtractionResult {
        $summary = $this->normalizeText((string) ($response->summary ?? ''), 'summary');

        $claims = [];
        $seenClaims = [];

        foreach ($response->claims as $claim) {
            $text = $this->normalizeText((string) $claim->text, 'claim text');
            $importance = $this->normalizeImportance((float) $claim->importance);
            $verifiable = (bool) $claim->verifiable;
            $type = strtolower(trim((string) $claim->type));
            $context = $this->normalizeText((string) $claim->context, 'claim context');

            if (! in_array($type, self::ALLOWED_CLAIM_TYPES, true)) {
                throw AiProviderException::validation([
                    "Claim type '{$type}' is not allowed.",
                ]);
            }

            $fingerprint = $this->calculateFingerprint($text, $type, $context);
            if (isset($seenClaims[$fingerprint])) {
                continue;
            }

            $seenClaims[$fingerprint] = true;

            $claims[] = [
                'text' => $text,
                'importance' => round($importance, 4),
                'verifiable' => $verifiable,
                'type' => $type,
                'context' => $context,
            ];
        }

        usort($claims, fn (array $left, array $right): int => $right['importance'] <=> $left['importance']);

        $entities = [];
        $seenEntities = [];
        foreach ($response->entities as $entity) {
            $name = $this->normalizeText((string) $entity->name, 'entity name');
            $type = strtolower(trim((string) $entity->type));
            $key = $name.'|'.$type;
            if (isset($seenEntities[$key])) {
                continue;
            }
            $seenEntities[$key] = true;
            $entities[] = ['name' => $name, 'type' => $type];
        }

        $sources = [];
        $seenSources = [];
        foreach ($response->sources as $source) {
            $name = $this->normalizeText((string) $source->name, 'source name');
            $url = $source->url !== null && $source->url !== ''
                ? $this->normalizeUrl((string) $source->url)
                : null;
            $key = $name.'|'.($url ?? '');
            if (isset($seenSources[$key])) {
                continue;
            }
            $seenSources[$key] = true;
            $sourcePayload = ['name' => $name];
            if ($url !== null) {
                $sourcePayload['url'] = $url;
            }
            $sources[] = $sourcePayload;
        }

        $redFlags = [];
        foreach ($response->redFlags as $redFlag) {
            $value = $this->normalizeText((string) $redFlag, 'red flag');
            if ($value !== '') {
                $redFlags[] = $value;
            }
        }

        $redFlags = array_values(array_unique($redFlags));

        return new ClaimExtractionResult(
            summary: $summary,
            claims: $claims,
            entities: $entities,
            sources: $sources,
            redFlags: $redFlags,
            promptVersion: $promptVersion,
        );
    }

    private function assertArticleIsUsable(Article $article): void
    {
        $title = trim((string) ($article->title ?? ''));
        $content = trim((string) ($article->content ?? ''));

        if ($title === '' || $content === '') {
            throw AiProviderException::validation([
                'Article title and content are required before claim extraction.',
            ]);
        }
    }

    private function assertStructuredOutputIsAllowed(ExtractedClaimsResponse $response, string $prompt, array $schema): void
    {
        $lowerPrompt = strtolower($prompt);
        if (str_contains($lowerPrompt, 'verdict') || str_contains($lowerPrompt, 'credibility_score')) {
            // prompt explicitly forbids verdict fields; this guard is kept defensive.
        }

        $properties = $schema['properties'] ?? [];

        if (array_key_exists('verdict', $properties) || array_key_exists('credibility_score', $properties)) {
            throw AiProviderException::validation([
                'Structured output must not include verdict or credibility_score.',
            ]);
        }

        if ($response->hasForbiddenKeys()) {
            throw AiProviderException::validation([
                'Structured output contains forbidden verdict or credibility_score fields.',
            ]);
        }
    }

    private function normalizeImportance(float $importance): float
    {
        if ($importance < 0 || $importance > 1) {
            throw AiProviderException::validation([
                "Claim importance '{$importance}' is outside the allowed range 0..1.",
            ]);
        }

        return $importance;
    }

    /**
     * @param  array{text: string, importance: float, verifiable: bool, type: string, context: string}  $claim
     * @return array{text: string, importance: float, verifiable: bool, type: string, context: string}
     */
    private function normalizeClaimPayload(array $claim): array
    {
        $text = $this->normalizeText((string) ($claim['text'] ?? ''), 'claim text');
        $type = strtolower(trim((string) ($claim['type'] ?? '')));

        if (! in_array($type, self::ALLOWED_CLAIM_TYPES, true)) {
            throw AiProviderException::validation([
                "Claim type '{$type}' is not allowed.",
            ]);
        }

        return [
            'text' => $text,
            'importance' => $this->normalizeImportance((float) ($claim['importance'] ?? 0.0)),
            'verifiable' => (bool) ($claim['verifiable'] ?? false),
            'type' => $type,
            'context' => $this->normalizeText((string) ($claim['context'] ?? ''), 'claim context'),
        ];
    }

    /**
     * @param  array<int, array{text: string, importance: float, verifiable: bool, type: string, context: string}>  $claims
     */
    private function validatePersistableClaims(array $claims): void
    {
        foreach ($claims as $claim) {
            $this->normalizeClaimPayload($claim);
        }
    }

    private function normalizeText(string $value, string $label): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($normalized === '') {
            throw AiProviderException::validation([
                "Missing or empty {$label}.",
            ]);
        }

        return $normalized;
    }

    private function normalizeUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            throw AiProviderException::validation(['Source URL cannot be empty.']);
        }

        return $normalized;
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function calculateFingerprint(string $text, string $type, string $context): string
    {
        return hash('sha256', strtolower($text).'|'.strtolower($type).'|'.strtolower($context));
    }
}
