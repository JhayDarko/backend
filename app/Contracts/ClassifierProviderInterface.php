<?php

namespace App\Contracts;

use App\Services\AI\DTO\ClassificationResult;

interface ClassifierProviderInterface
{
    /**
     * Classify article content using a text classification model.
     *
     * @return ClassificationResult
     */
    public function classify(
        string $title,
        string $content,
        ?string $description = null,
    ): ClassificationResult;

    public function getName(): string;

    public function getModel(): string;
}
