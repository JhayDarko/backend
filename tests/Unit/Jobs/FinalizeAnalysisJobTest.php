<?php

namespace Tests\Unit\Jobs;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Jobs\ClassifyArticleJob;
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

        $this->app->instance(AnalystProviderInterface::class, new class implements AnalystProviderInterface
        {
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

    public function test_classifier_failure_does_not_prevent_analysis_finalization(): void
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
            'title' => 'Noticia sin clasificación',
            'content' => 'Contenido de noticia que se analizará sin el clasificador.',
            'url' => 'https://eltiempo.com/noticia-sin-clasificacion',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        (new ClassifyArticleJob($analysis->id))->failed(new \RuntimeException('BERT service unavailable.'));

        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame(['classifier_unavailable' => true], $analysis->metadata);

        (new FinalizeAnalysisJob($analysis->id))->handle(
            app(SourceAnalysisService::class),
            app(GeminiAnalystService::class),
            app(CredibilityScoreService::class),
        );

        $analysis->refresh();
        $score = $analysis->score;

        $this->assertSame('completed', $analysis->status);
        $this->assertNotNull($score);
        $this->assertSame(50.0, (float) $score->language_score);
        $this->assertFalse($score->metadata['signals']['language_signal_available']);
        $this->assertTrue($analysis->metadata['classifier_unavailable']);
    }

    public function test_critical_finalization_failure_still_marks_analysis_failed(): void
    {
        $article = Article::query()->create([
            'title' => 'Noticia con fallo crítico',
            'content' => 'Contenido que no pudo completar el análisis.',
            'url' => 'https://example.test/fallo-critico',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);
        $sourceAnalysis = \Mockery::mock(SourceAnalysisService::class);
        $sourceAnalysis->shouldReceive('analyze')->once()->andThrow(new \RuntimeException('Critical source analysis failure.'));

        try {
            (new FinalizeAnalysisJob($analysis->id))->handle(
                $sourceAnalysis,
                app(GeminiAnalystService::class),
                app(CredibilityScoreService::class),
            );
            $this->fail('Expected the critical finalization failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Critical source analysis failure.', $exception->getMessage());
        }

        $this->assertSame('failed', $analysis->fresh()->status);
    }
}
