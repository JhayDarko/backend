<?php

namespace Tests\Unit\Services\Search;

use App\Jobs\SearchClaimEvidenceJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\Source;
use App\Services\Search\EvidenceSearchService;
use App\Services\Search\SearchProviderManager;
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

        $manager = new class implements \App\Contracts\Search\SearchProviderInterface {
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

    public function test_search_claim_evidence_job_dispatches_for_claim(): void
    {
        Queue::fake();

        $job = new SearchClaimEvidenceJob(1);

        $this->assertInstanceOf(SearchClaimEvidenceJob::class, $job);
        Queue::assertNothingPushed();
    }
}
