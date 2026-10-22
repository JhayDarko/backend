<?php

namespace Tests\Feature\Api;

use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleAnalysisPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    use RefreshDatabase;

    public function test_analysis_request_returns_accepted_and_queues_the_pipeline(): void
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
            'url' => 'https://example.test/noticia-prueba',
            'canonical_url' => 'https://example.test/noticia-prueba',
            'title' => 'Prueba controlada de análisis de noticia',
            'description' => 'Noticia artificial creada para validar el flujo del pipeline.',
            'content' => 'El precio del combustible subió un 8% este martes, según el ministerio. La autoridad explicó que la medida entrará en vigor el próximo lunes.',
            'author_name' => 'Autor de prueba',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $response = $this->postJson('/api/v1/articles/'.$article->id.'/analyze');

        $response->assertStatus(202);

        $analysisId = $response->json('data.id');
        $this->assertNotNull($analysisId);

        $analysis = Analysis::findOrFail($analysisId);
        $this->assertSame('pending', $analysis->status);

        Queue::assertPushed(AnalyzeArticleJob::class, fn (AnalyzeArticleJob $job): bool => $job->analysisId === $analysisId);

        $statusResponse = $this->getJson('/api/v1/analyses/'.$analysisId.'/status');

        $statusResponse->assertOk()
            ->assertJsonPath('data.id', $analysisId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress', 0);
    }
}
