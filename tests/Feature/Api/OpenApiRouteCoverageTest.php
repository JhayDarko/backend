<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiRouteCoverageTest extends TestCase
{
    private const PUBLIC_API_PREFIX = 'api/public/v1/';

    private const HTTP_METHODS = [
        'delete',
        'get',
        'head',
        'options',
        'patch',
        'post',
        'put',
        'trace',
    ];

    public function test_openapi_paths_and_operations_match_registered_public_api_routes(): void
    {
        $document = Yaml::parseFile(base_path('public/api-docs/openapi.yaml'));
        $paths = $document['paths'] ?? null;

        $this->assertIsArray($paths);

        $registeredOperations = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, self::PUBLIC_API_PREFIX)) {
                continue;
            }

            $path = $this->normalizePath('/'.substr($uri, strlen(self::PUBLIC_API_PREFIX)));
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registeredOperations[] = strtolower($method).' '.$path;
                }
            }
        }

        $this->assertNotEmpty($registeredOperations, 'No public API routes were registered.');

        $documentedOperations = [];
        foreach ($paths as $path => $pathItem) {
            $this->assertIsString($path);
            $this->assertIsArray($pathItem);

            foreach (array_keys($pathItem) as $method) {
                if (! in_array(strtolower((string) $method), self::HTTP_METHODS, true)) {
                    continue;
                }

                $documentedOperations[] = strtolower((string) $method).' '.$this->normalizePath($path);
            }
        }

        sort($registeredOperations);
        sort($documentedOperations);

        $this->assertSame(
            [],
            array_values(array_diff($registeredOperations, $documentedOperations)),
            'Registered public API operations are missing from OpenAPI.',
        );
        $this->assertSame(
            [],
            array_values(array_diff($documentedOperations, $registeredOperations)),
            'OpenAPI documents operations that are not registered in the public API.',
        );
    }

    private function normalizePath(string $path): string
    {
        return preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;
    }
}
