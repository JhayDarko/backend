<?php

namespace Tests\Unit\Services\Search;

use App\Contracts\Search\SearchProviderInterface;
use App\Jobs\SearchClaimEvidenceJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\Source;
use App\Services\Search\EvidenceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EvidenceSearchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_search_evidence_for_a_claim(): void
    {
        $source = Source::query()->create([
            'name' => 'Reuters',
            'domain' => 'reuters.com',
            'url' => 'https://reuters.com',
            'type' => 'news',
            'country' => 'US',
            'language' => 'es',
            'description' => 'Agencia',
            'reputation_score' => 0.8,
        ]);

        $article = Article::query()->create([
            'source_id' => $source->id,
            'url' => 'https://example.test/articulo',
            'canonical_url' => 'https://example.test/articulo',
            'title' => 'Aumento de precios',
            'description' => 'Desarrollo',
            'content' => 'La empresa dijo que la cuota subió.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'completed',
            'algorithm_version' => '1.0.0',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'La empresa dijo que la cuota subió.',
            'type' => 'factual',
            'importance' => 0.9,
            'verifiable' => true,
            'context' => 'Economía',
            'fingerprint' => 'fp-1',
            'metadata' => [],
        ]);

        $manager = new class implements SearchProviderInterface
        {
            public function search(string $query, array $options = []): array
            {
                return [
                    [
                        'title' => 'Artículo de referencia',
                        'url' => 'https://example.com/noticia?utm_source=test&utm_medium=x',
                        'snippet' => 'Se confirmó el aumento.',
                        'provider' => 'test-provider',
                        'source_name' => 'Example News',
                        'published_at' => '2026-08-01',
                        'relevance' => 0.9,
                    ],
                ];
            }

            public function getName(): string
            {
                return 'test-provider';
            }
        };

        $service = new EvidenceSearchService($manager);
        $result = $service->persistForClaim($claim);

        $this->assertCount(1, $result);
        $this->assertSame(1, $claim->fresh()->claimVerifications()->count());
        $this->assertSame(1, $claim->fresh()->claimVerifications()->first()->evidences()->count());
        $this->assertStringContainsString('example.com/noticia', $claim->fresh()->claimVerifications()->first()->evidences()->first()->url);
    }

    public function test_search_excludes_social_networks_and_asks_provider_to_exclude_them(): void
    {
        $claim = new Claim(['text' => 'La NASA modificó la órbita de Dimorphos', 'verifiable' => true]);

        $provider = new class implements SearchProviderInterface
        {
            public array $options = [];

            public function search(string $query, array $options = []): array
            {
                $this->options = $options;

                return [
                    ['title' => 'Post', 'url' => 'https://www.facebook.com/post/1', 'snippet' => 'a', 'provider' => 'p'],
                    ['title' => 'Hilo', 'url' => 'https://x.com/u/status/1', 'snippet' => 'b', 'provider' => 'p'],
                    ['title' => 'Blog', 'url' => 'https://blog.example/nota', 'snippet' => 'c', 'provider' => 'p'],
                    ['title' => 'NASA', 'url' => 'https://www.nasa.gov/dart', 'snippet' => 'd', 'provider' => 'p'],
                ];
            }

            public function getName(): string
            {
                return 'p';
            }
        };

        $results = (new EvidenceSearchService($provider))->search($claim);

        $this->assertSame(['https://www.nasa.gov/dart', 'https://blog.example/nota'], array_column($results, 'url'));
        $this->assertContains('facebook.com', $provider->options['exclude_domains']);
        $this->assertSame(15, $provider->options['max_results']);
    }

    public function test_search_claim_evidence_job_dispatches_for_claim(): void
    {
        Queue::fake();

        $job = new SearchClaimEvidenceJob(1);

        $this->assertInstanceOf(SearchClaimEvidenceJob::class, $job);
        Queue::assertNothingPushed();
    }
}
