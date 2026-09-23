<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\AI\Providers\GeminiProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeminiProviderTest extends TestCase
{
    private GeminiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock configuration
        config(['ai.gemini.api_key' => 'test-api-key']);
        config(['ai.gemini.api_base_url' => 'https://generativelanguage.googleapis.com/v1beta']);
        config(['ai.gemini.model' => 'gemini-2.0-flash']);
        config(['ai.gemini.temperature' => 0.7]);
        config(['ai.timeout' => 60]);
        config(['ai.prompt_version' => '1.0.0']);

        $this->provider = new GeminiProvider();
    }

    #[Test]
    public function it_implements_ai_provider_interface(): void
    {
        $this->assertInstanceOf(AiProviderInterface::class, $this->provider);
    }

    #[Test]
    public function it_returns_provider_name(): void
    {
        $this->assertSame('gemini', $this->provider->getName());
    }

    #[Test]
    public function it_returns_model_name(): void
    {
        $this->assertSame('gemini-2.0-flash', $this->provider->getModel());
    }

    #[Test]
    public function it_extracts_claims_successfully(): void
    {
        $validResponse = [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'summary' => 'Article about climate change.',
                                    'claims' => [
                                        [
                                            'text' => 'Global temperatures are rising.',
                                            'importance' => 0.95,
                                            'verifiable' => true,
                                            'type' => 'factual',
                                            'context' => 'Scientists report rising global temperatures.',
                                        ],
                                    ],
                                    'entities' => [
                                        ['name' => 'Climate', 'type' => 'concept'],
                                    ],
                                    'sources' => [
                                        ['name' => 'NASA', 'url' => 'https://nasa.gov'],
                                    ],
                                    'red_flags' => [],
                                ]),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response($validResponse, 200),
        ]);

        $response = $this->provider->extractClaims(
            'Climate Change Update',
            'Global temperatures are rising according to NASA scientists.',
            'Latest climate data'
        );

        $this->assertInstanceOf(ExtractedClaimsResponse::class, $response);
        $this->assertSame('Article about climate change.', $response->summary);
        $this->assertCount(1, $response->claims);
        $this->assertSame('Global temperatures are rising.', $response->claims[0]->text);
        $this->assertTrue($response->claims[0]->verifiable);
        $this->assertSame('factual', $response->claims[0]->type);
    }

    #[Test]
    public function it_throws_on_missing_api_key(): void
    {
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('GEMINI_API_KEY is not configured');

        config(['ai.gemini.api_key' => '']);

        new GeminiProvider();
    }

    #[Test]
    public function it_throws_on_rate_limit(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(
                ['error' => ['message' => 'Rate limit exceeded']],
                429,
                ['Retry-After' => '60']
            ),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('rate limit exceeded');

        $this->provider->extractClaims(
            'Title',
            'Content'
        );
    }

    #[Test]
    public function it_throws_on_timeout(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response(
                ['error' => ['message' => 'Timeout']],
                504
            ),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('timed out');

        $this->provider->extractClaims(
            'Title',
            'Content'
        );
    }

    #[Test]
    public function it_throws_on_invalid_json_response(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'invalid json {',
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('Failed to parse JSON');

        $this->provider->extractClaims(
            'Title',
            'Content'
        );
    }

    #[Test]
    public function it_validates_importance_range(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Test.',
                                        'claims' => [
                                            [
                                                'text' => 'Invalid importance.',
                                                'importance' => 1.5,
                                                'verifiable' => true,
                                                'type' => 'factual',
                                                'context' => 'Context.',
                                            ],
                                        ],
                                        'entities' => [],
                                        'sources' => [],
                                        'red_flags' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('importance out of range');

        $this->provider->extractClaims('Title', 'Content');
    }

    #[Test]
    public function it_validates_claim_type(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Test.',
                                        'claims' => [
                                            [
                                                'text' => 'Test claim.',
                                                'importance' => 0.5,
                                                'verifiable' => true,
                                                'type' => 'invalid_type',
                                                'context' => 'Context.',
                                            ],
                                        ],
                                        'entities' => [],
                                        'sources' => [],
                                        'red_flags' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('invalid type');

        $this->provider->extractClaims('Title', 'Content');
    }

    #[Test]
    public function it_handles_multiple_claims(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Multi-claim article.',
                                        'claims' => [
                                            [
                                                'text' => 'First claim.',
                                                'importance' => 0.8,
                                                'verifiable' => true,
                                                'type' => 'factual',
                                                'context' => 'Context 1.',
                                            ],
                                            [
                                                'text' => 'Second claim.',
                                                'importance' => 0.6,
                                                'verifiable' => false,
                                                'type' => 'opinion',
                                                'context' => 'Context 2.',
                                            ],
                                            [
                                                'text' => 'Third claim.',
                                                'importance' => 0.9,
                                                'verifiable' => true,
                                                'type' => 'statistical',
                                                'context' => 'Context 3.',
                                            ],
                                        ],
                                        'entities' => [],
                                        'sources' => [],
                                        'red_flags' => ['Potential bias detected'],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->provider->extractClaims('Title', 'Content');

        $this->assertCount(3, $response->claims);
        $this->assertSame('First claim.', $response->claims[0]->text);
        $this->assertSame('Second claim.', $response->claims[1]->text);
        $this->assertSame('Third claim.', $response->claims[2]->text);
        $this->assertCount(1, $response->redFlags);
    }

    #[Test]
    public function it_handles_optional_fields(): void
    {
        Http::fake([
            '*generativelanguage.googleapis.com*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'summary' => 'Test.',
                                        'claims' => [],
                                        'entities' => [],
                                        'sources' => [],
                                        'red_flags' => [],
                                    ]),
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Should not throw - all fields optional in response
        $response = $this->provider->extractClaims(
            'Title',
            'Content'
            // description omitted
        );

        $this->assertInstanceOf(ExtractedClaimsResponse::class, $response);
    }
}
