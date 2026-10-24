<?php

namespace Tests\Feature\Api;

use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisListAndRetryApiTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();

        $source = Source::create([
            'name' => 'El Confidencial',
            'domain' => 'elconfidencial.com',
            'url' => 'https://elconfidencial.com',
        ]);

        $this->article = Article::create([
            'source_id' => $source->id,
            'url' => 'https://elconfidencial.com/noticia',
            'title' => 'Noticia de Prueba',
            'content' => 'Contenido de la noticia',
        ]);
    }

    public function test_can_list_analyses_paginated(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $art = Article::create([
                'source_id' => $this->article->source_id,
                'url' => "https://elconfidencial.com/noticia-{$i}",
                'title' => "Noticia {$i}",
                'content' => "Contenido {$i}",
            ]);

            Analysis::create([
                'article_id' => $art->id,
                'status' => 'completed',
                'algorithm_version' => '1.0.0',
            ]);
        }

        $response = $this->getJson('/api/v1/analyses?per_page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4);
    }

    public function test_can_list_analyses_with_cursor_pagination(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $article = Article::create([
                'source_id' => $this->article->source_id,
                'url' => "https://elconfidencial.com/cursor-{$i}",
                'title' => "Cursor analysis {$i}",
                'content' => "Content {$i}",
            ]);
            Analysis::create([
                'article_id' => $article->id,
                'status' => 'completed',
                'algorithm_version' => '1.2.0',
            ]);
        }

        $firstPage = $this->getJson('/api/v1/analyses?pagination=cursor&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $cursor = $firstPage->json('meta.next_cursor');
        $this->assertNotEmpty($cursor);

        $secondPage = $this->getJson('/api/v1/analyses?pagination=cursor&per_page=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstIds = collect($firstPage->json('data'))->pluck('id');
        $secondIds = collect($secondPage->json('data'))->pluck('id');

        $this->assertSame([], $firstIds->intersect($secondIds)->all());
    }

    public function test_can_filter_analyses_by_status(): void
    {
        $art1 = Article::create(['source_id' => $this->article->source_id, 'url' => 'https://test/1', 'title' => 'T1', 'content' => 'C1']);
        $art2 = Article::create(['source_id' => $this->article->source_id, 'url' => 'https://test/2', 'title' => 'T2', 'content' => 'C2']);

        Analysis::create(['article_id' => $art1->id, 'status' => 'completed', 'algorithm_version' => '1.0.0']);
        Analysis::create(['article_id' => $art2->id, 'status' => 'failed', 'algorithm_version' => '1.0.0']);

        $response = $this->getJson('/api/v1/analyses?status=failed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_can_filter_analyses_by_final_verdict(): void
    {
        $art1 = Article::create(['source_id' => $this->article->source_id, 'url' => 'https://test/v1', 'title' => 'T1', 'content' => 'C1']);
        $art2 = Article::create(['source_id' => $this->article->source_id, 'url' => 'https://test/v2', 'title' => 'T2', 'content' => 'C2']);

        Analysis::create(['article_id' => $art1->id, 'status' => 'completed', 'final_verdict' => 'credible', 'algorithm_version' => '1.0.0']);
        Analysis::create(['article_id' => $art2->id, 'status' => 'completed', 'final_verdict' => 'false', 'algorithm_version' => '1.0.0']);

        $response = $this->getJson('/api/v1/analyses?final_verdict=false');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.final_verdict', 'false');
    }

    public function test_can_retry_failed_analysis(): void
    {
        Queue::fake();

        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'failed',
            'error' => 'Previous network timeout',
            'algorithm_version' => '1.0.0',
            'completed_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/analyses/{$analysis->id}/retry");

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $analysis->refresh();
        $this->assertSame('pending', $analysis->status);
        $this->assertNull($analysis->error);
        $this->assertNull($analysis->completed_at);

        Queue::assertPushed(AnalyzeArticleJob::class, function (AnalyzeArticleJob $job) use ($analysis) {
            return $job->analysisId === $analysis->id;
        });
    }

    public function test_cannot_retry_analysis_that_is_currently_processing(): void
    {
        Queue::fake();

        $analysis = Analysis::create([
            'article_id' => $this->article->id,
            'status' => 'processing',
            'algorithm_version' => '1.0.0',
            'started_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/analyses/{$analysis->id}/retry");

        $response->assertStatus(409)
            ->assertJsonPath('code', 'analysis_already_active')
            ->assertJsonPath('message', 'El análisis ya está en curso o en cola.');

        Queue::assertNotPushed(AnalyzeArticleJob::class);
    }
}
