<?php

namespace Tests\Unit\Services\AI;

use App\DTO\Source\SourceAnalysisResult;
use App\Exceptions\AiProviderException;
use App\Models\Article;
use App\Models\Claim;
use App\Services\AI\Providers\GeminiAnalystProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiAnalystProviderTest extends TestCase
{
    private GeminiAnalystProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.gemini.api_key', 'test-api-key');
        Config::set('ai.gemini.model', 'gemini-2.0-flash');
        $this->provider = new GeminiAnalystProvider;
    }

    public function test_synthesize_parses_valid_gemini_response_correctly(): void
    {
        $mockJson = [
            'summary' => 'El artículo contiene afirmaciones respaldadas por fuentes oficiales.',
            'consistency_score' => 0.88,
            'suggested_verdict' => 'credible',
            'claim_evaluations' => [
                [
                    'claim_id' => 1,
                    'claim_text' => 'El precio de la gasolina subió un 5%',
                    'stance' => 'supported',
                    'reasoning' => 'Datos coincidentes con el reporte del ministerio.',
                    'confidence' => 0.92,
                ],
            ],
            'fallacies_and_red_flags' => ['Ninguna detectada'],
            'bias_indicators' => ['Tono neutral'],
            'key_findings' => ['Datos verificados'],
        ];

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode($mockJson),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $article = new Article([
            'title' => 'Noticia de prueba',
            'content' => 'Contenido de la noticia',
            'url' => 'https://ejemplo.com/noticia',
        ]);
        $article->id = 1;

        $claim = new Claim([
            'text' => 'El precio de la gasolina subió un 5%',
            'importance' => 0.9,
            'verifiable' => true,
        ]);
        $claim->id = 1;

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'ejemplo.com',
            trustTier: 'mainstream_press',
            reputationScore: 0.80,
            isKnownFactChecker: false,
        );

        $result = $this->provider->synthesize(
            article: $article,
            claims: collect([$claim]),
            verifications: collect([]),
            evidences: collect([]),
            prediction: null,
            sourceAnalysis: $sourceAnalysis,
        );

        $this->assertSame('El artículo contiene afirmaciones respaldadas por fuentes oficiales.', $result->summary);
        $this->assertSame(0.88, $result->consistencyScore);
        $this->assertSame('credible', $result->suggestedVerdict);
        $this->assertCount(1, $result->claimEvaluations);
        $this->assertSame('supported', $result->claimEvaluations[0]->stance);
        $this->assertSame(0.92, $result->claimEvaluations[0]->confidence);
    }

    public function test_synthesize_throws_rate_limit_exception_on_429(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => 'Resource exhausted'], 429),
        ]);

        $article = new Article(['title' => 'T', 'content' => 'C']);
        $article->id = 1;

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'ejemplo.com',
            trustTier: 'unknown',
            reputationScore: 0.50,
            isKnownFactChecker: false,
        );

        $this->expectException(AiProviderException::class);
        $this->provider->synthesize(
            article: $article,
            claims: collect([]),
            verifications: collect([]),
            evidences: collect([]),
            prediction: null,
            sourceAnalysis: $sourceAnalysis,
        );
    }

    public function test_synthesize_throws_exception_on_malformed_response(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'Invalid non-json string',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $article = new Article(['title' => 'T', 'content' => 'C']);
        $article->id = 1;

        $sourceAnalysis = new SourceAnalysisResult(
            domain: 'ejemplo.com',
            trustTier: 'unknown',
            reputationScore: 0.50,
            isKnownFactChecker: false,
        );

        $this->expectException(AiProviderException::class);
        $this->provider->synthesize(
            article: $article,
            claims: collect([]),
            verifications: collect([]),
            evidences: collect([]),
            prediction: null,
            sourceAnalysis: $sourceAnalysis,
        );
    }
}
