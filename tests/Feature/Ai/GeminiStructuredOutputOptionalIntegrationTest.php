<?php

namespace Tests\Feature\Ai;

use App\Services\AI\Providers\GeminiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiStructuredOutputOptionalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_gemini_structured_output_integration(): void
    {
        $apiKey = trim((string) config('ai.gemini.api_key', ''));

        if ($apiKey === '') {
            $this->markTestSkipped('GEMINI_API_KEY is not configured. Optional Gemini integration test skipped.');
        }

        $model = (string) config('ai.gemini.model', 'gemini-2.0-flash');
        $title = 'Prueba controlada: aumento del precio del combustible';
        $content = 'El ministerio informó este martes que el precio del combustible subió un 8% respecto al mes anterior. La agencia estatal dijo que la medida entrará en vigor el próximo lunes. El presidente explicó que la decisión fue necesaria para estabilizar el mercado.';
        $description = 'Noticia artificial de prueba para validación del esquema estructurado.';

        $startedAt = microtime(true);
        $provider = new GeminiProvider;
        $response = $provider->extractClaims($title, $content, $description);
        $duration = round(microtime(true) - $startedAt, 3);

        $this->assertNotEmpty($response->summary);
        $this->assertGreaterThan(0, count($response));
        $this->assertFalse($response->hasForbiddenKeys());

        foreach ($response->claims as $claim) {
            $this->assertNotEmpty($claim->text);
            $this->assertGreaterThanOrEqual(0.0, $claim->importance);
            $this->assertLessThanOrEqual(1.0, $claim->importance);
            $this->assertContains($claim->type, ['factual', 'statistical', 'temporal', 'causal', 'attribution', 'quote', 'prediction', 'opinion']);
            $this->assertNotEmpty($claim->context);
            $this->assertIsBool($claim->verifiable);
        }

        $safeMetrics = [
            'claims_count' => count($response),
            'types' => array_values(array_unique(array_map(static fn ($claim) => $claim->type, $response->claims))),
            'importance' => array_map(static fn ($claim) => round((float) $claim->importance, 4), $response->claims),
            'duration_seconds' => $duration,
            'model' => $model,
        ];

        fwrite(STDOUT, json_encode($safeMetrics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
    }
}
