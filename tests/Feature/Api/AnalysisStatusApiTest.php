<?php

namespace Tests\Feature\Api;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\Score;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalysisStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();

        $source = Source::create([
            'name' => 'Test Source',
            'domain' => 'test.org',
            'url' => 'https://test.org',
        ]);

        $this->article = Article::create([
            'source_id' => $source->id,
            'url' => 'https://test.org/news-item',
            'title' => 'Sample News',
            'content' => 'Sample content for testing status endpoint.',
        ]);
    }

    public function test_returns_status_for_pending_analysis(): void
    {
        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.id', $analysis->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.step', 'queued')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.progress_details.claims_count', 0)
            ->assertJsonPath('data.progress_details.has_score', false)
            ->assertJsonPath('data.final_verdict', null);
    }

    public function test_returns_status_for_processing_analysis_with_claims(): void
    {
        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'processing',
            'algorithm_version' => '1.0.0',
            'started_at' => now()->subSeconds(5),
        ]);

        Claim::create([
            'analysis_id' => $analysis->id,
            'text' => 'Claim number one',
            'fingerprint' => hash('sha256', 'Claim number one'),
            'importance' => 0.9,
            'verifiable' => true,
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.step', 'verifying_evidence')
            ->assertJsonPath('data.progress', 60)
            ->assertJsonPath('data.progress_details.claims_count', 1);
    }

    public function test_returns_status_for_completed_analysis(): void
    {
        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'completed',
            'algorithm_version' => '1.0.0',
            'credibility_score' => 88.50,
            'confidence_score' => 0.92,
            'final_verdict' => 'credible',
            'started_at' => now()->subSeconds(10),
            'completed_at' => now(),
        ]);

        Score::create([
            'analysis_id' => $analysis->id,
            'evidence_score' => 90,
            'source_quality_score' => 85,
            'corroboration_score' => 88,
            'independence_score' => 90,
            'consistency_score' => 85,
            'language_score' => 92,
            'transparency_score' => 88,
            'final_score' => 88.50,
            'algorithm_version' => '1.0.0',
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.step', 'completed')
            ->assertJsonPath('data.final_verdict', 'credible')
            ->assertJsonPath('data.credibility_score', '88.50')
            ->assertJsonPath('data.confidence_score', '0.92')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.progress_details.has_score', true);
    }

    public function test_returns_status_for_failed_analysis(): void
    {
        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'failed',
            'algorithm_version' => '1.0.0',
            'error' => 'External AI provider timeout',
            'started_at' => now()->subSeconds(15),
            'completed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/analyses/{$analysis->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.step', 'failed')
            ->assertJsonPath('data.progress', 20)
            ->assertJsonPath('data.error', 'External AI provider timeout');
    }

    public function test_returns_404_for_unknown_analysis_status(): void
    {
        $response = $this->getJson('/api/v1/analyses/99999/status');

        $response->assertNotFound();
    }
}
