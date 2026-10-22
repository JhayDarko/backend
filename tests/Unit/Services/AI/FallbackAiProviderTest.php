<?php

namespace Tests\Unit\Services\AI;

use App\Exceptions\AiProviderException;
use App\Services\AI\Providers\FallbackAiProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FallbackAiProviderTest extends TestCase
{
    public function test_it_uses_openai_when_gemini_is_rate_limited(): void
    {
        config([
            'ai.provider' => 'gemini',
            'ai.fallback_enabled' => true,
            'ai.fallback_provider' => 'openai',
            'ai.gemini.api_key' => 'gemini-test-key',
            'ai.gemini.api_keys' => ['gemini-test-key'],
            'ai.gemini.model' => 'gemini-test-model',
            'ai.openai.api_key' => 'openai-test-key',
            'ai.openai.model' => 'gpt-test-model',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
            'https://api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Resumen generado por fallback.',
                            'claims' => [[
                                'text' => 'La afirmación principal ocurrió.',
                                'importance' => 0.9,
                                'verifiable' => true,
                                'type' => 'factual',
                                'context' => 'Contexto de la afirmación.',
                            ]],
                            'entities' => [],
                            'sources' => [],
                            'red_flags' => [],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $provider = new FallbackAiProvider(new GeminiProvider, new OpenAiProvider);
        $result = $provider->extractClaims('Título', 'Contenido suficiente para extraer claims.');

        $this->assertSame('Resumen generado por fallback.', $result->summary);
        $this->assertSame('openai', $provider->getName());
        $this->assertSame('gpt-test-model', $provider->getModel());
        Http::assertSentCount(2);
    }

    public function test_openai_no_credits_is_reported_as_permanent_quota_exhaustion(): void
    {
        config([
            'ai.provider' => 'gemini',
            'ai.fallback_enabled' => true,
            'ai.fallback_provider' => 'openai',
            'ai.gemini.api_key' => 'gemini-test-key',
            'ai.gemini.api_keys' => ['gemini-test-key'],
            'ai.gemini.model' => 'gemini-test-model',
            'ai.openai.api_key' => 'openai-test-key',
            'ai.openai.model' => 'gpt-test-model',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429),
            'https://api.openai.com/*' => Http::response([
                'error' => [
                    'code' => 'insufficient_quota',
                    'type' => 'insufficient_quota',
                    'message' => 'You have no credits remaining.',
                ],
            ], 429),
        ]);

        try {
            (new FallbackAiProvider(new GeminiProvider, new OpenAiProvider))
                ->extractClaims('Título', 'Contenido suficiente para extraer claims.');
            $this->fail('Expected exhausted provider quota to fail explicitly.');
        } catch (AiProviderException $exception) {
            $this->assertSame(402, $exception->getCode());
            $this->assertStringContainsString('no credits remaining', $exception->getMessage());
        }
    }
}
