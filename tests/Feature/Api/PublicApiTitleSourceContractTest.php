<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PublicApiTitleSourceContractTest extends TestCase
{
    public function test_openapi_documents_public_title_source_without_session_routes(): void
    {
        $document = Yaml::parseFile(base_path('public/api-docs/openapi.yaml'));
        $paths = $document['paths'] ?? [];

        $this->assertArrayNotHasKey('/auth/login', $paths);
        $this->assertArrayNotHasKey('/auth/refresh', $paths);
        $this->assertSame(
            ['extracted', 'user', 'ai', 'fallback'],
            $document['components']['schemas']['PublicArticle']['properties']['title_source']['enum'] ?? null,
        );
        $this->assertSame(
            '#/components/schemas/PublicArticle',
            $paths['/analyses/{id}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['article']['$ref'] ?? null,
        );
        $this->assertSame(
            'daily_limit_exceeded',
            $paths['/analyses']['post']['responses']['429']['content']['application/json']['examples']['dailyLimitExceeded']['value']['code'] ?? null,
        );
    }
}
