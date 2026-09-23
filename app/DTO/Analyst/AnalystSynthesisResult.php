<?php

namespace App\DTO\Analyst;

final class AnalystSynthesisResult
{
    /**
     * @param array<int, AnalystClaimEvaluation> $claimEvaluations
     * @param array<int, string> $fallaciesAndRedFlags
     * @param array<int, string> $biasIndicators
     * @param array<int, string> $keyFindings
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        public string $summary,
        public array $claimEvaluations,
        public float $consistencyScore, // 0.0 to 1.0
        public array $fallaciesAndRedFlags = [],
        public array $biasIndicators = [],
        public array $keyFindings = [],
        public string $suggestedVerdict = 'inconclusive',
        public array $rawResponse = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'claim_evaluations' => array_map(fn (AnalystClaimEvaluation $item) => $item->toArray(), $this->claimEvaluations),
            'consistency_score' => $this->consistencyScore,
            'fallacies_and_red_flags' => $this->fallaciesAndRedFlags,
            'bias_indicators' => $this->biasIndicators,
            'key_findings' => $this->keyFindings,
            'suggested_verdict' => $this->suggestedVerdict,
            'raw_response' => $this->rawResponse,
        ];
    }
}

