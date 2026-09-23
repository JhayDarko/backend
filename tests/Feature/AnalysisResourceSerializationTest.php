<?php

namespace Tests\Feature;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use Tests\TestCase;

class AnalysisResourceSerializationTest extends TestCase
{
    public function test_analysis_api_serializes_persisted_verification_and_evidence_fields(): void
    {
        $article = Article::query()->create([
            'title' => 'Artículo de prueba',
            'content' => 'Contenido de prueba.',
            'url' => 'https://example.test/article',
        ]);
        $analysis = Analysis::query()->create(['article_id' => $article->id, 'status' => 'completed']);
        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Una afirmación verificable.',
            'type' => 'factual',
            'importance' => 0.8,
            'verifiable' => true,
            'context' => 'Contexto de prueba',
        ]);
        $verification = ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'external_fact_check',
            'provider' => 'google_fact_check',
            'status' => 'matched',
            'fact_check_url' => 'https://factcheck.example.test/result',
            'fact_check_title' => 'Resultado de verificación',
            'raw_rating' => 'True',
            'normalized_rating' => 'true',
        ]);
        $evidence = Evidence::query()->create([
            'url' => 'https://evidence.example.test/source',
            'title' => 'Fuente de evidencia',
            'metadata' => ['domain' => 'evidence.example.test', 'source_name' => 'Evidence source'],
        ]);
        $verification->evidences()->attach($evidence->id);

        $this->getJson("/api/v1/analyses/{$analysis->id}")
            ->assertOk()
            ->assertJsonPath('data.claims.0.type', 'factual')
            ->assertJsonPath('data.claims.0.verifiable', true)
            ->assertJsonPath('data.claims.0.verifications.0.normalized_rating', 'true')
            ->assertJsonPath('data.claims.0.verifications.0.url', 'https://factcheck.example.test/result')
            ->assertJsonPath('data.claims.0.verifications.0.evidences.0.domain', 'evidence.example.test')
            ->assertJsonPath('data.claims.0.verifications.0.evidences.0.source_name', 'Evidence source');
    }
}
