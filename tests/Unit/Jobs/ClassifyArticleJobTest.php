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

    public string $classifiedTitle = '';

    public string $classifiedContent = '';

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(ClassifierProviderInterface::class, new class($test) implements ClassifierProviderInterface
        {
            public function __construct(private ClassifyArticleJobTest $test) {}

            public function classify(string $title, string $content, ?string $description = null): ClassificationResult
            {
                $this->test->classifiedTitle = $title;
                $this->test->classifiedContent = $content;

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
        $this->assertSame('', $this->classifiedTitle);
        $this->assertSame($article->content, $this->classifiedContent);
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

    public function test_permanent_classifier_failure_is_recorded_without_failing_analysis(): void
    {
        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo-bert-failure',
            'title' => 'Título de prueba',
            'content' => 'Contenido de prueba.',
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
            'algorithm_version' => '1.0.0',
        ]);

        $exception = new \RuntimeException('BERT service unavailable.');

        (new ClassifyArticleJob($analysis->id))->failed($exception);

        $analysis->refresh();

        $this->assertSame('processing', $analysis->status);
        $this->assertSame(['classifier_unavailable' => true], $analysis->metadata);
        $this->assertNull($analysis->error);
        $this->assertNull($analysis->completed_at);
    }
}
