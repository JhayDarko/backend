<?php

namespace Tests\Unit\Jobs;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Jobs\FinalizeAnalysisJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Score;
use App\Models\Source;
use App\Services\AI\GeminiAnalystService;
use App\Services\Scoring\CredibilityScoreService;
use App\Services\Source\SourceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalizeAnalysisJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AnalystProviderInterface::class, new class implements AnalystProviderInterface {
            public function synthesize(
                Article $article,
                $claims,
                $verifications,
                $evidences,
                $prediction,
                SourceAnalysisResult $sourceAnalysis
            ): AnalystSynthesisResult {
                return new AnalystSynthesisResult(
                    summary: 'Síntesis de prueba completada.',
                    claimEvaluations: [],
                    consistencyScore: 0.85,
                    suggestedVerdict: 'probably_credible',
                );
            }

            public function getName(): string
            {
                return 'gemini_analyst';
            }

            public function getModel(): string
            {
                return 'gemini-2.0-flash';
            }
        });
    }

    public function test_finalize_analysis_job_creates_score_and_completes_analysis(): void
    {
        $source = Source::query()->create([
            'name' => 'El Tiempo',
            'domain' => 'eltiempo.com',
            'url' => 'https://eltiempo.com',
            'type' => 'news',
            'reputation_score' => 0.80,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'title' => 'Crecimiento económico del país',
            'content' => 'El PIB creció según el reporte oficial del gobierno.',
            'url' => 'https://eltiempo.com/economia/crecimiento',
            'author_name' => 'Redacción Economía',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $job = new FinalizeAnalysisJob($analysis->id);
        $job->handle(
            app(SourceAnalysisService::class),
            app(GeminiAnalystService::class),
            app(CredibilityScoreService::class)
        );

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertNotNull($analysis->credibility_score);
        $this->assertNotNull($analysis->confidence_score);
        $this->assertNotNull($analysis->final_verdict);
        $this->assertNotNull($analysis->completed_at);
        $this->assertNull($analysis->error);

        $this->assertDatabaseHas('scores', [
            'analysis_id' => $analysis->id,
        ]);

        $score = $analysis->score;
        $this->assertNotNull($score);
        $this->assertNotNull($score->evidence_score);
        $this->assertNotNull($score->source_quality_score);
        $this->assertNotNull($score->final_score);
        $this->assertIsArray($score->metadata);
    }

    public function test_finalize_analysis_job_is_idempotent(): void
    {
        $article = Article::query()->create([
            'title' => 'Prueba Idempotencia',
            'content' => 'Contenido de prueba',
            'url' => 'https://ejemplo.com/nota',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        $job = new FinalizeAnalysisJob($analysis->id);
        $job->handle(
            app(SourceAnalysisService::class),
            app(GeminiAnalystService::class),
            app(CredibilityScoreService::class)
        );

        $firstScoreId = $analysis->fresh()->score->id;

        // Run second time
        $job->handle(
            app(SourceAnalysisService::class),
            app(GeminiAnalystService::class),
            app(CredibilityScoreService::class)
        );

        $this->assertSame(1, Score::query()->where('analysis_id', $analysis->id)->count());
        $this->assertSame($firstScoreId, $analysis->fresh()->score->id);
    }
}

