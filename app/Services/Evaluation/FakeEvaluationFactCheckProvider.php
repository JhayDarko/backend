<?php

namespace App\Services\Evaluation;

use App\Contracts\FactCheck\FactCheckProviderInterface;

final class FakeEvaluationFactCheckProvider implements FactCheckProviderInterface
{
    public function search(string $query): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'evaluation_fake_factcheck';
    }
}
