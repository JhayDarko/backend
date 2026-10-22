<?php

namespace Tests\Unit\Jobs;

use App\Jobs\VerifyClaimJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Services\FactCheck\FactCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

final class VerifyClaimJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_rechecks_a_previous_no_match(): void
    {
        $claim = $this->createClaim();
        ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'provider' => 'google_fact_check',
            'relation' => 'external_fact_check',
            'status' => 'no_match',
            'normalized_rating' => 'unknown',
            'fingerprint' => 'no-match-fingerprint',
        ]);

        $service = Mockery::mock(FactCheckService::class);
        $service->shouldReceive('verifyClaim')
            ->once()
            ->with(Mockery::type(Claim::class))
            ->andReturn(['status' => 'no_match', 'results' => []]);

        (new VerifyClaimJob($claim->id))->handle($service);
    }

    public function test_job_skips_a_legacy_match_when_raw_rating_is_interpretable(): void
    {
        $claim = $this->createClaim();
        ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'provider' => 'google_fact_check',
            'relation' => 'external_fact_check',
            'status' => 'matched',
            'raw_rating' => 'Engañoso',
            'normalized_rating' => 'unknown',
            'fingerprint' => 'legacy-rating-fingerprint',
        ]);

        $service = Mockery::mock(FactCheckService::class);
        $service->shouldNotReceive('verifyClaim');

        (new VerifyClaimJob($claim->id))->handle($service);
    }

    private function createClaim(): Claim
    {
        $article = Article::query()->create([
            'title' => 'Prueba de fact-check',
            'content' => 'Contenido suficiente para probar la revalidación de una afirmación.',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        return Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'La afirmación fue calificada como engañosa.',
            'type' => 'factual',
            'importance' => 0.9,
            'verifiable' => true,
            'fingerprint' => hash('sha256', 'claim-factcheck-job'),
        ]);
    }
}
