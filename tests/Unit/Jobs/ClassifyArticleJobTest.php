<?php

namespace Tests\Unit\Jobs;

use App\Contracts\ClassifierProviderInterface;
use App\Jobs\ClassifyArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use App\Services\AI\DTO\ClassificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassifyArticleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ClassifierProviderInterface::class, new class implements ClassifierProviderInterface
        {
            public function classify(string $title, string $content, ?string $description = null): ClassificationResult
            {
                return ClassificationResult::from([
                    'label' => 'REAL',
                    'confidence' => 0.91,
                    'probability' => 0.91,
                    'model' => 'Juanillaberia/spanish-fake-news-classifier',
                ]);
            }

            public function getName(): string
            {
                return 'bert';
            }

            public function getModel(): string
            {
                return 'Juanillaberia/spanish-fake-news-classifier';
            }
        });
    }

    public function test_it_persists_a_prediction_for_an_analysis(): void
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
            'url' => 'https://example.test/articulo-bert',
            'canonical_url' => 'https://example.test/articulo-bert',
            'title' => 'Título de prueba',
            'description' => 'Descripción del artículo',
            'content' => 'Este contenido es un artículo correcto con datos verificables.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new ClassifyArticleJob($analysis->id);
        $job->handle();

        $analysis->refresh();

        $this->assertSame('pending', $analysis->status);
        $this->assertSame(1, $analysis->predictions()->count());
        $this->assertSame('REAL', $analysis->predictions()->first()->prediction_label);
    }

    public function test_it_is_idempotent_on_retry(): void
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
            'url' => 'https://example.test/articulo-bert-retry',
            'canonical_url' => 'https://example.test/articulo-bert-retry',
            'title' => 'Título de prueba',
            'description' => 'Descripción del artículo',
            'content' => 'Artículo para verificar idempotencia del clasificador.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'algorithm_version' => '1.0.0',
        ]);

        $job = new ClassifyArticleJob($analysis->id);
        $job->handle();
        $job->handle();

        $this->assertSame(1, $analysis->fresh()->predictions()->count());
        $this->assertSame('pending', $analysis->fresh()->status);
    }
}
