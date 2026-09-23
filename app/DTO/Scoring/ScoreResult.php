<?php

namespace App\DTO\Scoring;

final class ScoreResult
{
    /**
     * @param array<string, mixed> $breakdown
     */
    public function __construct(
        public float $evidenceScore,
        public float $sourceQualityScore,
        public float $corroborationScore,
        public float $independenceScore,
        public float $consistencyScore,
        public float $languageScore,
        public float $transparencyScore,
        public float $finalScore,
        public string $finalVerdict,
        public float $confidenceScore,
        public string $algorithmVersion = '1.0.0',
        public array $breakdown = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evidence_score' => $this->evidenceScore,
            'source_quality_score' => $this->sourceQualityScore,
            'corroboration_score' => $this->corroborationScore,
            'independence_score' => $this->independenceScore,
            'consistency_score' => $this->consistencyScore,
            'language_score' => $this->languageScore,
            'transparency_score' => $this->transparencyScore,
            'final_score' => $this->finalScore,
            'final_verdict' => $this->finalVerdict,
            'confidence_score' => $this->confidenceScore,
            'algorithm_version' => $this->algorithmVersion,
            'breakdown' => $this->breakdown,
        ];
    }
}

