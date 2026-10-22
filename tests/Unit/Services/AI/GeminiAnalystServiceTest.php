<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Models\Source;
use App\Services\AI\GeminiAnalystService;
use App\Services\Source\SourceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiAnalystServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_orchestrates_data_and_calls_provider(): void
    {
        $mockProvider = new class implements AnalystProviderInterface
        {
            public bool $called = false;

            public function synthesize(
                Article $article,
                $claims,
                $verifications,
                $evidences,
                $prediction,
                SourceAnalysisResult $sourceAnalysis
            ): AnalystSynthesisResult {
                $this->called = true;

                return new AnalystSynthesisResult(
                    summary: 'Síntesis correcta',
                    claimEvaluations: [],
                    consistencyScore: 0.90,
                    suggestedVerdict: 'credible',
                );
            }

            public function getName(): string
            {
                return 'mock_analyst';
            }

            public function getModel(): string
            {
                return 'mock-model';
            }
        };

        $sourceService = new SourceAnalysisService;
        $service = new GeminiAnalystService($mockProvider, $sourceService);

        $source = Source::query()->create([
            'name' => 'El Espectador',
            'domain' => 'elespectador.com',
            'url' => 'https://elespectador.com',
            'type' => 'news',
            'reputation_score' => 0.82,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'title' => 'Noticia confirmada',
            'content' => 'Contenido verificado',
            'url' => 'https://elespectador.com/noticia',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Afirmación verificable',
            'importance' => 0.8,
            'verifiable' => true,
        ]);

        $verification = ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'normalized_rating' => 'true',
            'fact_check_url' => 'https://chequeado.com/verificacion-1',
            'fact_check_title' => 'Verificación positiva',
        ]);

        $evidence = Evidence::query()->create([
            'url' => 'https://chequeado.com/verificacion-1',
            'title' => 'Chequeado título',
            'snippet' => 'Snippet evidencia',
        ]);

        $verification->evidences()->sync([$evidence->id]);

        $result = $service->analyze($analysis);

        $this->assertTrue($mockProvider->called);
        $this->assertSame('Síntesis correcta', $result->summary);
        $this->assertSame('credible', $result->suggestedVerdict);
    }
}
