<?php

namespace App\Services\AI\DTO;

final class ClassificationResult
{
    /**
     * @param  array<string, mixed>  $rawData
     */
    public function __construct(
        public readonly string $label,
        public readonly float $confidence,
        public readonly float $probability,
        public readonly string $model,
        public readonly array $rawData = [],
    ) {}

    /**
     * @param  array{label?: string, confidence?: float|int|string, probability?: float|int|string, model?: string, rawData?: array<string, mixed>}  $data
     */
    public static function from(array $data): self
    {
        $label = strtoupper(trim((string) ($data['label'] ?? '')));
        $confidence = (float) ($data['confidence'] ?? $data['probability'] ?? 0.0);
        $probability = (float) ($data['probability'] ?? $data['confidence'] ?? $confidence);
        $model = (string) ($data['model'] ?? 'unknown-model');

        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \InvalidArgumentException('Classification confidence must be between 0 and 1.');
        }

        if ($probability < 0.0 || $probability > 1.0) {
            throw new \InvalidArgumentException('Classification probability must be between 0 and 1.');
        }

        return new self(
            label: $label,
            confidence: $confidence,
            probability: $probability,
            model: $model,
            rawData: $data,
        );
    }
}
