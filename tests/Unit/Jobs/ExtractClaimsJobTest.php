<?php

namespace Tests\Unit\Jobs;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Jobs\AnalyzeArticleJob;
use App\Jobs\ClassifyArticleJob;
use App\Jobs\ExtractClaimsJob;
use App\Jobs\SearchClaimEvidenceJob;
use App\Jobs\VerifyClaimJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\Verification\ClaimExtractionService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExtractClaimsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                return ExtractedClaimsResponse::from([
                    'summary' => 'Resumen del artículo',
                    'claims' => [
                        [
                            'text' => 'El precio subió un 20%.',
                            'importance' => 0.9,
                            'verifiable' => true,
                            'type' => 'statistical',
                            'context' => 'Contexto del precio',
                        ],
                    ],
                    'entities' => [],
                    'sources' => [],
                    'red_flags' => [],
                ]);
            }

            public function getName(): string
            {
                return 'gemini';
            }

            public function getModel(): string
            {
                return 'gemini-2.0-flash';
            }
        });
    }

    public function test_analyze_article_job_dispatches_extract_claims_job(): void
    {
        Queue::fake();

        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Agencia de noticias',
            'reputation_score' => 0.8,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo-dispatch',
            'canonical_url' => 'https://example.test/articulo-dispatch',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new AnalyzeArticleJob($analysis->id);
        $job->handle();

        Queue::assertPushed(ExtractClaimsJob::class, function (ExtractClaimsJob $queuedJob) use ($analysis) {
            return $queuedJob->analysisId === $analysis->id;
        });
    }

    public function test_extract_claims_job_updates_analysis_and_persists_claims(): void
    {
        Bus::fake();

        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Agencia de noticias',
            'reputation_score' => 0.8,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo',
            'canonical_url' => 'https://example.test/articulo',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'model_provider' => 'gemini',
            'model_name' => 'gemini-2.0-flash',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new ExtractClaimsJob($analysis->id);
        $job->handle(new ClaimExtractionService);

        $analysis->refresh();

        $this->assertSame('processing', $analysis->status);
        $this->assertSame(1, $analysis->claims()->count());
        $this->assertSame('El precio subió un 20%.', $analysis->claims()->first()->text);
    }

    public function test_extract_claims_job_is_idempotent_on_retry(): void
    {
        Bus::fake();

        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Agencia de noticias',
            'reputation_score' => 0.8,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo-retry',
            'canonical_url' => 'https://example.test/articulo-retry',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new ExtractClaimsJob($analysis->id);
        $job->handle(new ClaimExtractionService);
        $job->handle(new ClaimExtractionService);

        $this->assertSame(1, $analysis->fresh()->claims()->count());
        $this->assertSame('processing', $analysis->fresh()->status);
    }

    public function test_extract_claims_job_dispatches_verification_jobs_for_extracted_claims(): void
    {
        Bus::fake();

        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Agencia de noticias',
            'reputation_score' => 0.8,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo-verificacion',
            'canonical_url' => 'https://example.test/articulo-verificacion',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new ExtractClaimsJob($analysis->id);
        $job->handle(new ClaimExtractionService);

        Bus::assertBatched(function (PendingBatch $batch) use ($analysis): bool {
            $claimIds = $analysis->claims()->pluck('id');
            $jobs = collect($batch->jobs);

            return $jobs->contains(fn ($job) => $job instanceof ClassifyArticleJob && $job->analysisId === $analysis->id)
                && $jobs->contains(fn ($job) => $job instanceof VerifyClaimJob && $claimIds->contains($job->claimId))
                && $jobs->contains(fn ($job) => $job instanceof SearchClaimEvidenceJob && $claimIds->contains($job->claimId));
        });
    }

    public function test_extract_claims_job_does_not_retry_when_provider_quota_is_exhausted(): void
    {
        Bus::fake();
        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface
        {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                throw AiProviderException::quotaExhausted('OpenAI', 'You have no credits remaining.');
            }

            public function getName(): string
            {
                return 'openai';
            }

            public function getModel(): string
            {
                return 'gpt-4o-mini';
            }
        });

        $source = Source::query()->create([
            'name' => 'Test source',
            'domain' => 'example.test',
            'url' => 'https://example.test',
        ]);
        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => null,
            'title' => 'Noticia de prueba',
            'content' => 'Contenido de noticia suficientemente largo para extracción.',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
        ]);

        $job = (new ExtractClaimsJob($analysis->id))->withFakeQueueInteractions();
        $job->handle(new ClaimExtractionService);

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertStringContainsString('cuota', strtolower($analysis->error));
        $this->assertStringNotContainsString('OpenAI', $analysis->error);
        $job->assertFailed();
        Bus::assertNothingBatched();
    }
}
