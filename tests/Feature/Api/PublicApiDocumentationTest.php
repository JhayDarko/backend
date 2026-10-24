<?php

namespace Tests\Feature\Api;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PublicApiDocumentationTest extends TestCase
{
    public function test_documented_page_operations_match_openapi_methods_and_paths(): void
    {
        $document = Yaml::parseFile(base_path('public/api-docs/openapi.yaml'));
        $page = file_get_contents(base_path('frontend/src/app/pages/api-docs.ts'));

        $this->assertIsArray($document['paths'] ?? null);
        $this->assertIsString($page);
        $this->assertMatchesRegularExpression(
            '/export const PUBLIC_API_OPERATIONS = \[(.*?)\] as const;/s',
            $page,
        );
        $this->assertStringContainsString('{{ example.method }} {{ example.path }}', $page);

        preg_match(
            '/export const PUBLIC_API_OPERATIONS = \[(.*?)\] as const;/s',
            $page,
            $operationsBlock,
        );
        preg_match_all(
            "/\{ method: '(GET|POST|PUT|PATCH|DELETE)', path: '([^']+)'/",
            $operationsBlock[1],
            $operations,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($operations, 'The public documentation must show API operations.');
        foreach ($operations as [, $method, $path]) {
            $this->assertArrayHasKey($path, $document['paths']);
            $this->assertArrayHasKey(strtolower($method), $document['paths'][$path]);
        }
    }

    public function test_documented_plan_defaults_match_config_plans_defaults(): void
    {
        $config = file_get_contents(base_path('config/plans.php'));
        $page = file_get_contents(base_path('frontend/src/app/pages/api-docs.ts'));

        $this->assertIsString($config);
        $this->assertIsString($page);

        foreach ([
            ['variable' => 'PLAN_FREE_PER_MINUTE', 'name' => 'Gratuito', 'field' => 'perMinute'],
            ['variable' => 'PLAN_FREE_ANALYSES_PER_DAY', 'name' => 'Gratuito', 'field' => 'analysesPerDay'],
            ['variable' => 'PLAN_PRO_PER_MINUTE', 'name' => 'Pro', 'field' => 'perMinute'],
            ['variable' => 'PLAN_PRO_ANALYSES_PER_DAY', 'name' => 'Pro', 'field' => 'analysesPerDay'],
        ] as $planValue) {
            preg_match(
                "/env\\('{$planValue['variable']}',\\s*(\\d+)\\)/",
                $config,
                $configValue,
            );
            preg_match(
                "/name: '{$planValue['name']}'.*?{$planValue['field']}:\\s*(\\d+)/s",
                $page,
                $pageValue,
            );

            $this->assertNotEmpty($configValue, "Missing {$planValue['variable']} default in config/plans.php.");
            $this->assertNotEmpty($pageValue, "Missing {$planValue['field']} for {$planValue['name']} in the docs.");
            $this->assertSame((int) $configValue[1], (int) $pageValue[1]);
        }
    }
}
