<?php

namespace Tests\Unit\Services\FactCheck;

use App\Exceptions\AiProviderException;
use App\Services\FactCheck\GoogleFactCheckProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleFactCheckProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fact_check.enabled' => true,
            'fact_check.google.api_key' => 'test-key',
            'fact_check.google.timeout' => 10,
            'fact_check.google.connect_timeout' => 5,
        ]);
    }

    public function test_it_returns_normalized_results_for_google_claims(): void
    {
        Http::fake([
            '*' => Http::response([
                'claims' => [
                    [
                        'text' => 'El agua hierve a 100 grados.',
                        'languageCode' => 'es',
                        'claimant' => 'Example',
                        'claimDate' => '2026-08-01T00:00:00Z',
                        'claimReview' => [
                            [
                                'title' => 'Verdicto',
                                'url' => 'https://example.com/factcheck/1',
                                'textualRating' => 'True',
                                'reviewDate' => '2026-08-02T00:00:00Z',
                                'languageCode' => 'es',
                                'publisher' => ['name' => 'PolitiFact', 'site' => 'politifact.com'],
                            ],
                            [
                                'title' => 'Revisión en español',
                                'url' => 'https://example.org/factcheck/2',
                                'textualRating' => 'Engañoso',
                                'reviewDate' => '2026-08-03T00:00:00Z',
                                'languageCode' => 'es',
                                'publisher' => ['name' => 'Verificador', 'site' => 'example.org'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new GoogleFactCheckProvider;
        $results = $provider->search('El agua hierve a 100 grados.');

        $this->assertCount(2, $results);
        $this->assertSame('google_fact_check', $results[0]['provider']);
        $this->assertSame('True', $results[0]['raw_rating']);
        $this->assertSame('true', $results[0]['normalized_rating']);
        $this->assertSame('PolitiFact', $results[0]['publisher']);
        $this->assertSame('politifact.com', $results[0]['publisher_site']);
        $this->assertSame('mostly_false', $results[1]['normalized_rating']);
        $this->assertSame('Verificador', $results[1]['publisher']);
    }

    public function test_it_returns_empty_when_google_has_no_matches(): void
    {
        Http::fake([
            '*' => Http::response(['claims' => []], 200),
        ]);

        $provider = new GoogleFactCheckProvider;
        $results = $provider->search('Texto sin coincidencia');

        $this->assertSame([], $results);
    }

    public function test_it_throws_for_rate_limit(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Too many requests']], 429),
        ]);

        $this->expectException(AiProviderException::class);

        $provider = new GoogleFactCheckProvider;
        $provider->search('Texto de prueba');
    }

    public function test_it_throws_for_invalid_json_response(): void
    {
        Http::fake([
            '*' => Http::response('not-json', 200),
        ]);

        $this->expectException(AiProviderException::class);

        $provider = new GoogleFactCheckProvider;
        $provider->search('Texto de prueba');
    }
}
