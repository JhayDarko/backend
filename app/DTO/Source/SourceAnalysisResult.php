<?php

namespace App\DTO\Source;

final class SourceAnalysisResult
{
    /**
     * @param  array<int, string>  $flags
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $domain,
        public string $trustTier,
        public float $reputationScore,
        public bool $isKnownFactChecker,
        public array $flags = [],
        public array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'trust_tier' => $this->trustTier,
            'reputation_score' => $this->reputationScore,
            'is_known_fact_checker' => $this->isKnownFactChecker,
            'flags' => $this->flags,
            'details' => $this->details,
        ];
    }
}
