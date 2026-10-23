<?php

namespace App\Services\Evaluation;

use App\Contracts\ClassifierProviderInterface;
use App\Services\AI\DTO\ClassificationResult;

final class FakeEvaluationClassifier implements ClassifierProviderInterface
{
    public function classify(string $title, string $content, ?string $description = null): ClassificationResult
    {
        if (str_contains($content, 'EVAL_BERT_FAIL')) {
            throw new \RuntimeException('Simulated non-critical classifier failure.');
        }

        return new ClassificationResult('REAL', 0.8, 0.8, $this->getModel(), ['fake' => true]);
    }

    public function getName(): string
    {
        return 'evaluation_fake';
    }

    public function getModel(): string
    {
        return 'evaluation-fake-classifier-v1';
    }
}
