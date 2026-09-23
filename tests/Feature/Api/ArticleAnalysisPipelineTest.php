<?php

namespace Tests\Feature\Api;

use App\Contracts\AiProviderInterface;
use App\Jobs\ExtractClaimsJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\Verification\ClaimExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleAnalysisPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(AiProviderInterface::class, new class implements AiProviderInterface {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                return ExtractedClaimsResponse::from([
                    'summary' => 'Artículo de prueba para validación del pipeline',
                    'claims' => [
                        [
                            'text' => 'El precio del combustible subió un 8%.',
                            'importance' => 0.9,
                            'verifiable' => true,
                            'type' => 'statistical',
                            'context' => 'Datos del ministerio sobre el precio',
                        ],
                    ],
                    'entities' => [
                        ['name' => 'Ministerio', 'type' => 'organization'],
                    ],
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

        $this->app->instance(\App\Contracts\ClassifierProviderInterface::class, new class implements \App\Contracts\ClassifierProviderInterface {
            public function classify(string $title, string $content, ?string $description = null): \App\Services\AI\DTO\ClassificationResult
            {
                return new \App\Services\AI\DTO\ClassificationResult(
                    label: 'REAL',
                    confidence: 0.90,
                    probability: 0.90,
                    model: 'spanish-fake-news-classifier',
                    rawData: ['prediction' => 'REAL'],
                );
            }

            public function getName(): string
            {
                return 'bert';
            }

            public function getModel(): string
            {
                return 'spanish-fake-news-classifier';
            }
        });
    }

    public function test_article_analysis_pipeline_end_to_end(): void
    {
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

        $response = $this->postJson('/api/v1/articles/' . $article->id . '/analyze');

        $response->assertStatus(202);

        $analysisId = $response->json('data.id');
        $this->assertNotNull($analysisId);

        $analysis = Analysis::findOrFail($analysisId);
        $this->assertNotSame('failed', $analysis->status);

        (new ExtractClaimsJob($analysis->id))->handle(new ClaimExtractionService());

        $analysis->refresh();

        $this->assertSame('completed', $analysis->status);
        $this->assertSame(1, $analysis->claims()->count());

        $showResponse = $this->getJson('/api/v1/analyses/' . $analysisId);

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.id', $analysisId)
            ->assertJsonPath('data.status', 'completed');
    }
}
