<?php

namespace Tests\Feature\Api;

use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisQuotaTest extends TestCase
{
    public function test_quota_endpoint_reports_remaining_daily_and_concurrent_capacity(): void
    {
        $user = $this->actingAsUser();
        config([
            'quotas.analyses_per_day' => 10,
            'quotas.concurrent_analyses_per_user' => 2,
        ]);
        $user->analysisUsages()->create(['date' => today()->toDateString(), 'analyses' => 3]);

        $this->getJson('/api/v1/auth/quota')
            ->assertOk()
            ->assertJsonPath('data.used', 3)
            ->assertJsonPath('data.limit', 10)
            ->assertJsonPath('data.remaining', 7)
            ->assertJsonPath('data.active', 0)
            ->assertJsonPath('data.concurrent_limit', 2)
            ->assertJsonPath('data.resets_at', now()->startOfDay()->addDay()->toIso8601String());
    }

    public function test_new_analysis_consumes_quota_but_duplicate_request_does_not(): void
    {
        $this->actingAsUser();
        Queue::fake();
        $article = $this->createArticle('quota-dedup');

        $this->postJson("/api/v1/articles/{$article->id}/analyze")
            ->assertAccepted();
        $this->postJson("/api/v1/articles/{$article->id}/analyze")
            ->assertOk();

        $this->assertDatabaseHas('user_analysis_usages', [
            'user_id' => $this->user->id,
            'date' => today()->toDateString(),
            'analyses' => 1,
        ]);
        Queue::assertPushed(AnalyzeArticleJob::class, 1);
    }

    public function test_daily_limit_returns_a_stable_error_and_reset_instant(): void
    {
        $user = $this->actingAsUser();
        config(['quotas.analyses_per_day' => 1]);
        Queue::fake();
        $user->analysisUsages()->create(['date' => today()->toDateString(), 'analyses' => 1]);
        $article = $this->createArticle('quota-daily');

        $this->postJson("/api/v1/articles/{$article->id}/analyze")
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'daily_quota_exceeded')
            ->assertJsonPath('reason', 'daily')
            ->assertJsonPath('resets_at', now()->startOfDay()->addDay()->toIso8601String());

        Queue::assertNothingPushed();
    }

    public function test_concurrent_limit_counts_pending_and_processing_analyses(): void
    {
        $this->actingAsUser();
        config(['quotas.concurrent_analyses_per_user' => 2]);
        Queue::fake();

        foreach (['pending', 'processing'] as $index => $status) {
            $article = $this->createArticle("quota-active-{$index}");
            Analysis::query()->create([
                'article_id' => $article->id,
                'user_id' => $this->user->id,
                'status' => $status,
                'algorithm_version' => '1.0.0',
            ]);
        }
        $article = $this->createArticle('quota-concurrent');

        $this->postJson("/api/v1/articles/{$article->id}/analyze")
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'daily_quota_exceeded')
            ->assertJsonPath('reason', 'concurrent')
            ->assertJsonPath('resets_at', now()->startOfDay()->addDay()->toIso8601String());

        Queue::assertNothingPushed();
    }

    public function test_retry_consumes_daily_quota_and_cannot_be_requeued_while_pending(): void
    {
        $user = $this->actingAsUser();
        config(['quotas.analyses_per_day' => 2]);
        Queue::fake();
        $article = $this->createArticle('quota-retry');
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'status' => 'failed',
            'algorithm_version' => '1.0.0',
        ]);

        $this->postJson("/api/v1/analyses/{$analysis->id}/retry")
            ->assertAccepted();
        $this->assertDatabaseHas('user_analysis_usages', [
            'user_id' => $user->id,
            'date' => today()->toDateString(),
            'analyses' => 1,
        ]);

        $this->postJson("/api/v1/analyses/{$analysis->id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'analysis_already_active');

        Queue::assertPushed(AnalyzeArticleJob::class, 1);
    }

    public function test_retry_is_blocked_when_the_daily_quota_is_already_used(): void
    {
        $user = $this->actingAsUser();
        config(['quotas.analyses_per_day' => 1]);
        Queue::fake();
        $article = $this->createArticle('quota-retry-limit');
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'status' => 'failed',
            'algorithm_version' => '1.0.0',
        ]);
        $user->analysisUsages()->create(['date' => today()->toDateString(), 'analyses' => 1]);

        $this->postJson("/api/v1/analyses/{$analysis->id}/retry")
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'daily_quota_exceeded');

        $this->assertSame('failed', $analysis->fresh()->status);
        Queue::assertNothingPushed();
    }

    private function createArticle(string $slug): Article
    {
        return Article::query()->create([
            'url' => "https://example.test/{$slug}",
            'title' => $slug,
            'content' => 'Contenido de prueba para comprobar el límite del análisis.',
        ]);
    }
}
