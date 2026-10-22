<?php

namespace Tests\Unit\Services\Verification;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Source;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\Verification\ClaimExtractionService;
use App\Services\Verification\DTO\ClaimExtractionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimExtractionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = new class implements AiProviderInterface
        {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                return ExtractedClaimsResponse::from([
                    'summary' => 'Resumen del artículo',
                    'claims' => [
                        [
                            'text' => 'El precio subió un 20%.',
                            'importance' => 0.9,
                            'verifiable' => true,
                            'type' => 'statistical',
                            'context' => 'Contexto del precio',
                        ],
                        [
                            'text' => 'El precio subió un 20%.',
                            'importance' => 0.9,
                            'verifiable' => true,
                            'type' => 'statistical',
                            'context' => 'Contexto del precio',
                        ],
                    ],
                    'entities' => [
                        ['name' => 'Empresa X', 'type' => 'organization'],
                    ],
                    'sources' => [
                        ['name' => 'Reuters', 'url' => 'https://reuters.com'],
                    ],
                    'red_flags' => ['Señal'],
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
        };

        $this->app->instance(AiProviderInterface::class, $provider);
    }

    public function test_it_persists_only_valid_and_unique_claims_into_analysis(): void
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
            'url' => 'https://example.test/aumento-precio',
            'canonical_url' => 'https://example.test/aumento-precio',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'model_provider' => 'gemini',
            'model_name' => 'gemini-2.0-flash',
            'algorithm_version' => '1.0.0',
        ]);

        $service = new ClaimExtractionService;
        $result = $service->extractAndPersist($article, $analysis);

        $this->assertInstanceOf(ClaimExtractionResult::class, $result);
        $this->assertSame(1, $analysis->claims()->count());

        $claim = $analysis->claims()->first();
        $this->assertSame('El precio subió un 20%.', $claim->text);
        $this->assertSame('statistical', $claim->type);
        $this->assertSame(0.9, (float) $claim->importance);
        $this->assertTrue((bool) $claim->verifiable);
        $this->assertNotNull($claim->fingerprint);
        $this->assertSame($claim->fingerprint, hash('sha256', strtolower($claim->text).'|statistical|'.strtolower($claim->context)));
    }

    public function test_it_is_idempotent_when_repeated(): void
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
            'url' => 'https://example.test/aumento-precio-2',
            'canonical_url' => 'https://example.test/aumento-precio-2',
            'title' => 'Aumento de precio',
            'description' => 'Descripción del artículo',
            'content' => 'El precio subió un 20%.',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
            'model_provider' => 'gemini',
            'model_name' => 'gemini-2.0-flash',
            'algorithm_version' => '1.0.0',
        ]);

        $service = new ClaimExtractionService;
        $service->extractAndPersist($article, $analysis);
        $service->extractAndPersist($article, $analysis);

        $this->assertSame(1, $analysis->claims()->count());
    }

    public function test_it_rejects_invalid_claims_before_persistence(): void
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
            'url' => 'https://example.test/title',
            'canonical_url' => 'https://example.test/title',
            'title' => 'Título',
            'description' => 'Descripción',
            'content' => 'Contenido',
            'author_name' => 'Autor',
            'language' => 'es',
            'metadata' => ['source' => 'manual'],
        ]);

        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'pending',
        ]);

        $service = new ClaimExtractionService;

        $result = new ClaimExtractionResult(
            summary: 'Resumen',
            claims: [[
                'text' => 'Claim válido',
                'importance' => 0.55,
                'verifiable' => true,
                'type' => 'factual',
                'context' => 'Contexto',
            ], [
                'text' => 'Claim inválido',
                'importance' => 1.5,
                'verifiable' => true,
                'type' => 'factual',
                'context' => 'Contexto',
            ]],
            entities: [],
            sources: [],
            redFlags: [],
            promptVersion: '1.0.0',
        );

        $this->expectException(AiProviderException::class);

        $service->persistClaims($analysis, $result);
    }
}
