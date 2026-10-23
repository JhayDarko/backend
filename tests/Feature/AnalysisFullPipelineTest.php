<?php

namespace Tests\Feature;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\ModelPrediction;
use App\Models\Score;
use App\Models\Source;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AnalysisFullPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    public function test_analysis_pipeline_exposes_claims_with_verifications_and_evidences(): void
    {
        Bus::fake();
        Http::fake();

        $source = Source::query()->create([
            'name' => 'News Agency',
            'domain' => 'example.com',
            'url' => 'https://example.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Test source',
        ]);

        $article = Article::query()->create([
            'title' => 'El cambio climático no es real',
            'text' => 'Algunos científicos dicen que el cambio climático es una mentira.',
            'url' => 'https://example.com/article',
            'source_id' => $source->id,
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'article_id',
                'status',
                'claims',
                'article' => ['id', 'title', 'url'],
            ],
        ]);
    }

    public function test_analysis_api_returns_empty_claims_for_pending_analysis(): void
    {
        Bus::fake();

        $source = Source::query()->create([
            'name' => 'News Agency',
            'domain' => 'example.com',
            'url' => 'https://example.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Test source',
        ]);

        $article = Article::query()->create([
            'title' => 'Test Article',
            'text' => 'Test content',
            'url' => 'https://example.com/article',
            'source_id' => $source->id,
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.claims', []);
    }

    public function test_completed_analysis_exposes_score_predictions_and_verdict(): void
    {
        $source = Source::query()->create([
            'name' => 'Chequeado',
            'domain' => 'chequeado.com',
            'url' => 'https://chequeado.com',
            'type' => 'fact_checker',
            'reputation_score' => 0.95,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'title' => 'Artículo verificado completamente',
            'text' => 'Texto con respaldo científico.',
            'url' => 'https://chequeado.com/nota-completa',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'completed',
            'credibility_score' => 92.50,
            'confidence_score' => 0.95,
            'final_verdict' => 'credible',
            'algorithm_version' => '1.0.0',
        ]);

        Score::query()->create([
            'analysis_id' => $analysis->id,
            'evidence_score' => 95.0,
            'source_quality_score' => 95.0,
            'corroboration_score' => 90.0,
            'independence_score' => 90.0,
            'consistency_score' => 95.0,
            'language_score' => 92.0,
            'transparency_score' => 85.0,
            'final_score' => 92.50,
            'algorithm_version' => '1.0.0',
            'metadata' => [
                'summary' => 'Alta credibilidad confirmada por múltiples fuentes.',
                'analyst_summary' => 'Las afirmaciones principales cuentan con revisiones directas.',
                'truth_assessment' => ['label' => 'mostly_true', 'display' => 'Mayormente cierta'],
                'verdict_rationale' => 'La cobertura de evidencia es suficiente.',
                'signals' => [
                    'evidence_coverage' => 1.0,
                    'fact_check_coverage' => 0.75,
                    'language_signal_available' => true,
                ],
                'claim_evaluations' => [[
                    'claim_id' => 1,
                    'claim_text' => 'El PIB creció.',
                    'stance' => 'supported',
                    'conclusion' => 'Respaldado por revisiones disponibles.',
                    'reasoning' => 'Una revisión directa confirma el dato.',
                    'evidence' => [],
                ]],
            ],
        ]);

        ModelPrediction::query()->create([
            'analysis_id' => $analysis->id,
            'prediction_label' => 'REAL',
            'confidence_score' => 0.96,
            'probability' => 0.96,
            'model_provider' => 'bert',
            'model_name' => 'spanish-fake-news-classifier',
            'algorithm_version' => '1.0.0',
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'article_id',
                'status',
                'credibility_score',
                'confidence_score',
                'final_verdict',
                'score' => [
                    'id',
                    'evidence_score',
                    'source_quality_score',
                    'corroboration_score',
                    'independence_score',
                    'consistency_score',
                    'language_score',
                    'transparency_score',
                    'final_score',
                    'metadata',
                    'summary',
                    'truth_assessment',
                    'verdict_rationale',
                    'evidence_coverage',
                    'fact_check_coverage',
                    'claim_evaluations',
                ],
                'predictions' => [
                    '*' => [
                        'prediction_label',
                        'confidence_score',
                        'probability',
                        'model_name',
                    ],
                ],
                'article' => ['id', 'title', 'url'],
            ],
        ]);

        $response->assertJsonPath('data.final_verdict', 'credible');
        $response->assertJsonPath('data.score.final_score', '92.50');
        $response->assertJsonPath('data.score.summary', 'Las afirmaciones principales cuentan con revisiones directas.');
        $response->assertJsonPath('data.score.truth_assessment.label', 'mostly_true');
        $response->assertJsonPath('data.score.language_signal_available', true);
        $response->assertJsonPath('data.score.claim_evaluations.0.stance', 'supported');
        $response->assertJsonPath('data.predictions.0.prediction_label', 'REAL');
    }
}
