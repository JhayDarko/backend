<?php

namespace Tests\Feature\Api;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\Score;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function completedAnalysis(): Analysis
    {
        $article = Article::create(['title' => 'Misión DART', 'content' => 'La NASA modificó la órbita de Dimorphos.']);
        $analysis = Analysis::create([
            'article_id' => $article->id, 'status' => 'completed', 'algorithm_version' => '1.2.0',
            'credibility_score' => 78.5, 'confidence_score' => 0.61, 'final_verdict' => 'probably_credible',
        ]);
        Score::create([
            'analysis_id' => $analysis->id, 'evidence_score' => 80, 'final_score' => 78.5, 'algorithm_version' => '1.2.0',
            'metadata' => [
                'analyst_summary' => 'La noticia está respaldada.',
                'truth_assessment' => ['label' => 'probably_true', 'display' => 'Probablemente cierta'],
                'weights' => ['evidence' => 0.72],
                'signals' => ['evidence_coverage' => 0.9, 'fact_check_coverage' => 0.5],
                'claim_evaluations' => [[
                    'claim_text' => 'La NASA modificó la órbita', 'stance' => 'supported', 'is_central' => true,
                    'included_in_verdict' => true, 'reasoning' => 'Confirmado.',
                    'fact_check_sources' => [], 'evidence' => [['title' => 'NASA', 'url' => 'https://nasa.gov/dart']],
                ]],
            ],
        ]);

        return $analysis;
    }

    public function test_json_report_contains_verdict_claims_and_methodology(): void
    {
        $analysis = $this->completedAnalysis();

        $this->getJson("/api/v1/analyses/{$analysis->id}/report/json")
            ->assertOk()
            ->assertJsonPath('data.verdict.label', 'Probablemente creíble')
            ->assertJsonPath('data.claims.0.stance', 'Respaldada')
            ->assertJsonPath('data.claims.0.is_central', true)
            ->assertJsonPath('data.algorithm_version', '1.2.0')
            ->assertJsonStructure(['data' => ['methodology' => ['weights', 'notes'], 'subscores', 'generated_at']]);
    }

    public function test_pdf_report_is_generated(): void
    {
        $analysis = $this->completedAnalysis();

        $response = $this->get("/api/v1/analyses/{$analysis->id}/report/pdf");

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_report_is_not_available_until_the_analysis_completes(): void
    {
        $article = Article::create(['title' => 'x', 'content' => 'contenido de prueba suficiente']);
        $analysis = Analysis::create(['article_id' => $article->id, 'status' => 'processing', 'algorithm_version' => '1.2.0']);

        $this->getJson("/api/v1/analyses/{$analysis->id}/report/json")->assertStatus(409);
        $this->getJson("/api/v1/analyses/{$analysis->id}/report/pdf")->assertStatus(409);
    }

    public function test_other_users_cannot_download_the_report(): void
    {
        $analysis = $this->completedAnalysis();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/analyses/{$analysis->id}/report/json")->assertForbidden();
    }
}
