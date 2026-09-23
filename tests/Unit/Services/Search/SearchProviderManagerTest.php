<?php

namespace Tests\Unit\Services\Search;

use App\Services\Search\SearchProviderManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchProviderManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('search.enabled', true);
        config()->set('search.default', 'tavily');
        config()->set('search.fallback', 'brave');
        config()->set('search.providers.tavily.enabled', true);
        config()->set('search.providers.tavily.api_key', 'tavily-key');
        config()->set('search.providers.brave.enabled', true);
        config()->set('search.providers.brave.api_key', 'brave-key');
    }

    public function test_it_uses_the_primary_search_provider(): void
    {
        Http::fake([
            'https://api.tavily.com/search*' => Http::response([
                'results' => [
                    [
                        'title' => 'Tavily result',
                        'url' => 'https://example.com/tavily',
                        'content' => 'Resultado de prueba',
                    ],
                ],
            ]),
        ]);

        $results = app(SearchProviderManager::class)->search('noticia falsa');

        $this->assertCount(1, $results);
        $this->assertSame('tavily', $results[0]->provider);
        $this->assertSame('https://example.com/tavily', $results[0]->url);
    }

    public function test_it_falls_back_to_brave_when_primary_provider_fails(): void
    {
        Http::fake([
            'https://api.tavily.com/search*' => Http::response([
                'error' => 'quota_exceeded',
            ], 429),
            'https://api.search.brave.com/res/v1/web/search*' => Http::response([
                'web' => [
                    'results' => [
                        [
                            'title' => 'Brave result',
                            'url' => 'https://example.com/brave',
                            'description' => 'Resultado alternativo',
                        ],
                    ],
                ],
            ]),
        ]);

        $results = app(SearchProviderManager::class)->search('noticia falsa');

        $this->assertCount(1, $results);
        $this->assertSame('brave', $results[0]->provider);
        $this->assertSame('https://example.com/brave', $results[0]->url);
    }
}
