<?php

namespace App\Services\Evaluation;

use App\Contracts\AiProviderInterface;
use App\Services\AI\DTO\ClaimData;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\AI\GeminiModelCircuit;
use RuntimeException;

final class FakeEvaluationAiProvider implements AiProviderInterface
{
    public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
    {
        if (str_contains($content, 'EVAL_RETRY_EXHAUST')) {
            GeminiModelCircuit::trip('evaluation-fake');
            throw new RuntimeException(
                'Simulated evaluation provider failure at https://provider.example.test/?key=EVAL_FAILED_URL_SECRET '
                .'Authorization: EVAL_FAILED_AUTH_SECRET',
            );
        }

        return new ExtractedClaimsResponse(
            summary: 'Simulated evaluation summary.',
            claims: [new ClaimData(
                text: 'La afirmación de prueba es verificable.',
                importance: 0.8,
                verifiable: true,
                type: 'factual',
                context: 'Prueba local sin red.',
            )],
            entities: [],
            sources: [],
            redFlags: [],
            rawData: [],
        );
    }

    public function getName(): string
    {
        return 'evaluation_fake';
    }

    public function getModel(): string
    {
        return 'evaluation-fake-v1';
    }
}
