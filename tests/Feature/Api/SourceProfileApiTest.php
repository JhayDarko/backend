<?php

namespace Tests\Feature\Api;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\Source;
use Tests\TestCase;

class SourceProfileApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    private function analyze(Source $source, float $score, string $verdict): Analysis
    {
        $article = Article::create(['source_id' => $source->id, 'title' => 't'.$score, 'content' => 'contenido '.$score]);

        return Analysis::create([
            'article_id' => $article->id, 'status' => 'completed', 'algorithm_version' => '1.2.0',
            'credibility_score' => $score, 'final_verdict' => $verdict,
        ]);
    }

    public function test_profile_is_computed_from_the_systems_own_analyses(): void
    {
        $source = Source::create(['name' => 'Medio X', 'domain' => 'medio-x.test', 'url' => 'https://medio-x.test', 'reputation_score' => 0.5]);
        $first = $this->analyze($source, 80, 'probably_credible');
        $this->analyze($source, 60, 'inconclusive');
        Claim::create(['analysis_id' => $first->id, 'text' => 'a', 'verifiable' => true, 'importance' => 0.9]);
        Claim::create(['analysis_id' => $first->id, 'text' => 'b', 'verifiable' => false, 'importance' => 0.2]);

        $this->getJson("/api/v1/sources/{$source->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.analyses_count', 2)
            ->assertJsonPath('data.average_score', 70)
            ->assertJsonPath('data.sample', 'baja')
            ->assertJsonPath('data.claims_count', 2)
            ->assertJsonPath('data.verifiable_claims_count', 1)
            ->assertJsonPath('data.verdicts.inconclusive', 1);
    }

    public function test_few_samples_are_pulled_toward_the_prior(): void
    {
        $source = Source::create(['name' => 'Medio Y', 'domain' => 'medio-y.test', 'url' => 'https://medio-y.test', 'reputation_score' => 0.5]);
        $this->analyze($source, 100, 'credible');

        // (1*100 + 5*50) / 6 = 58.3
        $this->getJson("/api/v1/sources/{$source->id}/profile")->assertJsonPath('data.historical_score', 58.3);
    }

    public function test_ranking_lists_sources_with_completed_analyses_only(): void
    {
        $with = Source::create(['name' => 'Con datos', 'domain' => 'con.test', 'url' => 'https://con.test']);
        Source::create(['name' => 'Sin datos', 'domain' => 'sin.test', 'url' => 'https://sin.test']);
        $this->analyze($with, 70, 'probably_credible');

        $this->getJson('/api/v1/sources')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.domain', 'con.test');
    }
}
