<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AnalyzeArticleJob;
use App\Jobs\ExtractClaimsJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalyzeArticleJobTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $source = Source::create([
            'name' => 'El País',
            'domain' => 'elpais.com',
            'url' => 'https://elpais.com',
        ]);

        $this->article = Article::create([
            'source_id' => $source->id,
            'url' => 'https://elpais.com/noticia',
            'title' => 'Noticia Importante',
            'content' => 'Contenido de la noticia...',
        ]);
    }

    public function test_handle_dispatches_extract_claims_job(): void
    {
        Queue::fake();

        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new AnalyzeArticleJob($analysis->id);
        $job->handle();

        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertNotNull($analysis->started_at);

        Queue::assertPushed(ExtractClaimsJob::class, function (ExtractClaimsJob $queuedJob) use ($analysis) {
            return $queuedJob->analysisId === $analysis->id;
        });
    }

    public function test_failed_method_updates_analysis_to_failed_state(): void
    {
        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'processing',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new AnalyzeArticleJob($analysis->id);
        $job->failed(new \RuntimeException('Catastrophic failure during analysis execution'));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('Catastrophic failure during analysis execution', $analysis->error);
        $this->assertNotNull($analysis->completed_at);
    }
}

