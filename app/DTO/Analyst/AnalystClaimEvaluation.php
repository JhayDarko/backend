<?php

namespace App\DTO\Analyst;

final class AnalystClaimEvaluation
{
    public function __construct(
        public int $claimId,
        public string $claimText,
        public string $stance, // supported, refuted, mixed, unverified
        public string $reasoning,
        public float $confidence, // 0.0 to 1.0
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'claim_text' => $this->claimText,
            'stance' => $this->stance,
            'reasoning' => $this->reasoning,
            'confidence' => $this->confidence,
        ];
    }
}
