<?php

namespace Tests\Unit\Services\FactCheck;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\Source;
use App\Services\FactCheck\FactCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_fact_check_results_for_a_claim(): void
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
            'url' => 'https://example.test/factcheck-article',
            'canonical_url' => 'https://example.test/factcheck-article',
            'title' => 'Artículo de prueba',
            'description' => 'Descripción',
            'content' => 'Contenido de prueba',
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
            'text' => 'El agua hierve a 100 grados.',
            'type' => 'factual',
            'importance' => 0.9,
            'verifiable' => true,
            'context' => 'Contexto',
            'fingerprint' => 'fp-1',
            'metadata' => [],
        ]);

        $this->app->instance(FactCheckProviderInterface::class, new class implements FactCheckProviderInterface
        {
            public function search(string $query): array
            {
                return [[
                    'provider' => 'google_fact_check',
                    'query' => $query,
                    'claim_text' => 'El agua hierve a 100 grados.',
                    'publisher' => 'PolitiFact',
                    'publisher_site' => 'politifact.com',
                    'fact_check_url' => 'https://example.com/factcheck/1',
                    'fact_check_title' => 'Verdicto',
                    'review_date' => '2026-08-02T00:00:00Z',
                    'language' => 'es',
                    'raw_rating' => 'True',
                    'normalized_rating' => 'true',
                    'metadata' => ['source' => 'google'],
                ]];
            }

            public function getName(): string
            {
                return 'google_fact_check';
            }
        });

        $service = new FactCheckService($this->app->make(FactCheckProviderInterface::class));
        $result = $service->verifyClaim($claim);

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $claim->fresh()->claimVerifications()->count());
        $this->assertSame('google_fact_check', $claim->fresh()->claimVerifications()->first()->provider);
        $this->assertSame('true', $claim->fresh()->claimVerifications()->first()->normalized_rating);
    }

    public function test_it_returns_no_match_when_provider_has_no_results(): void
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
            'url' => 'https://example.test/no-match-article',
            'canonical_url' => 'https://example.test/no-match-article',
            'title' => 'No match',
            'description' => 'Descripción',
            'content' => 'Contenido',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => [],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'completed',
            'algorithm_version' => '1.0.0',
        ]);

        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Afirmación sin coincidencias',
            'type' => 'factual',
            'importance' => 0.6,
            'verifiable' => true,
            'context' => 'Contexto',
            'fingerprint' => 'fp-2',
            'metadata' => [],
        ]);

        $this->app->instance(FactCheckProviderInterface::class, new class implements FactCheckProviderInterface
        {
            public function search(string $query): array
            {
                return [];
            }

            public function getName(): string
            {
                return 'google_fact_check';
            }
        });

        $service = new FactCheckService($this->app->make(FactCheckProviderInterface::class));
        $result = $service->verifyClaim($claim);

        $this->assertSame('no_match', $result['status']);
        $this->assertSame([], $result['results']);
        $this->assertDatabaseHas('claim_verifications', [
            'claim_id' => $claim->id,
            'provider' => 'google_fact_check',
            'status' => 'no_match',
            'normalized_rating' => 'unknown',
        ]);
    }
}
