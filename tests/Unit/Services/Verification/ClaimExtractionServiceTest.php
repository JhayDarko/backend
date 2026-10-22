<?php

namespace Tests\Unit\Services\Verification;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Models\Article;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\Verification\ClaimExtractionService;
use App\Services\Verification\DTO\ClaimExtractionResult;
use Mockery;
use Tests\TestCase;

class ClaimExtractionServiceTest extends TestCase
{
    public function test_it_extracts_and_normalizes_claims_from_article(): void
    {
        $provider = new class implements AiProviderInterface
        {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                return ExtractedClaimsResponse::from([
                    'summary' => 'Article summary',
                    'claims' => [
                        [
                            'text' => 'El aumento de temperatura es real.',
                            'importance' => 0.92,
                            'verifiable' => true,
                            'type' => 'factual',
                            'context' => 'Contexto 1',
                        ],
                        [
                            'text' => 'El aumento de temperatura es real.',
                            'importance' => 0.92,
                            'verifiable' => true,
                            'type' => 'factual',
                            'context' => 'Contexto 1',
                        ],
                        [
                            'text' => 'La temperatura subió en 2024.',
                            'importance' => 0.81,
                            'verifiable' => true,
                            'type' => 'temporal',
                            'context' => 'Contexto 2',
                        ],
                    ],
                    'entities' => [
                        ['name' => 'NASA', 'type' => 'organization'],
                        ['name' => '2024', 'type' => 'date'],
                    ],
                    'sources' => [
                        ['name' => 'Reuters', 'url' => 'https://reuters.com'],
                    ],
                    'red_flags' => ['Señal 1'],
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

        $article = new Article([
            'title' => 'Sube la temperatura global',
            'description' => 'Descripción del artículo',
            'content' => 'El aumento de temperatura es real. La temperatura subió en 2024.',
        ]);

        $service = new ClaimExtractionService;
        $result = $service->extract($article);

        $this->assertInstanceOf(ClaimExtractionResult::class, $result);
        $this->assertSame('Article summary', $result->summary);
        $this->assertCount(2, $result->claims);
        $this->assertSame('El aumento de temperatura es real.', $result->claims[0]['text']);
        $this->assertSame('factual', $result->claims[0]['type']);
        $this->assertSame('La temperatura subió en 2024.', $result->claims[1]['text']);
        $this->assertSame('temporal', $result->claims[1]['type']);
        $this->assertCount(1, $result->sources);
        $this->assertSame('Reuters', $result->sources[0]['name']);
        $this->assertSame('https://reuters.com', $result->sources[0]['url']);
        $this->assertArrayNotHasKey('verdict', $result->toArray());
    }

    public function test_it_rejects_model_verdict_and_extraneous_verification_fields(): void
    {
        $provider = new class implements AiProviderInterface
        {
            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                return ExtractedClaimsResponse::from([
                    'summary' => 'Resumen',
                    'claims' => [
                        [
                            'text' => 'La temperatura subió.',
                            'importance' => 0.7,
                            'verifiable' => true,
                            'type' => 'factual',
                            'context' => 'Contexto',
                        ],
                    ],
                    'entities' => [],
                    'sources' => [],
                    'red_flags' => [],
                    'verdict' => true,
                    'credibility_score' => 0.91,
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

        $article = new Article([
            'title' => 'Sube la temperatura',
            'content' => 'Texto del artículo',
        ]);

        $this->expectException(AiProviderException::class);

        (new ClaimExtractionService)->extract($article);
    }

    public function test_it_builds_prompt_version_and_uses_article_fields(): void
    {
        $provider = new class implements AiProviderInterface
        {
            public string $lastTitle;

            public string $lastContent;

            public ?string $lastDescription;

            public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
            {
                $this->lastTitle = $title;
                $this->lastContent = $content;
                $this->lastDescription = $description;

                return ExtractedClaimsResponse::from([
                    'summary' => 'Resumen',
                    'claims' => [
                        [
                            'text' => 'Claim',
                            'importance' => 0.4,
                            'verifiable' => false,
                            'type' => 'opinion',
                            'context' => 'Context',
                        ],
                    ],
                    'entities' => [],
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
        };

        $this->app->instance(AiProviderInterface::class, $provider);

        config(['ai.prompt_version' => 'v2.3.1']);

        $article = new Article([
            'title' => 'Título',
            'description' => 'Descripción corta',
            'content' => 'Contenido del artículo',
        ]);

        $result = (new ClaimExtractionService)->extract($article);

        $this->assertSame('Título', $provider->lastTitle);
        $this->assertSame('Contenido del artículo', $provider->lastContent);
        $this->assertSame('Descripción corta', $provider->lastDescription);
        $this->assertSame('Resumen', $result->summary);
        $this->assertSame('v2.3.1', $result->promptVersion);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
