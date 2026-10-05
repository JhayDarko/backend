<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'evidence_score' => $this->evidence_score,
            'source_quality_score' => $this->source_quality_score,
            'corroboration_score' => $this->corroboration_score,
            'independence_score' => $this->independence_score,
            'consistency_score' => $this->consistency_score,
            'language_score' => $this->language_score,
            'transparency_score' => $this->transparency_score,
            'final_score' => $this->final_score,
            'algorithm_version' => $this->algorithm_version,
            'metadata' => $this->metadata,
            'summary' => data_get($this->metadata, 'analyst_summary') ?: data_get($this->metadata, 'summary'),
            'truth_assessment' => data_get($this->metadata, 'truth_assessment'),
            'verdict_rationale' => data_get($this->metadata, 'verdict_rationale'),
            'evidence_coverage' => data_get($this->metadata, 'signals.evidence_coverage', 0),
            'fact_check_coverage' => data_get($this->metadata, 'signals.fact_check_coverage', 0),
            'suggested_verdict' => data_get($this->metadata, 'suggested_verdict'),
            'claim_evaluations' => data_get($this->metadata, 'claim_evaluations', []),
            'key_findings' => data_get($this->metadata, 'key_findings', []),
            'red_flags' => data_get($this->metadata, 'fallacies_and_red_flags', []),
            'bias_indicators' => data_get($this->metadata, 'bias_indicators', []),
            'created_at' => $this->created_at,
        ];
    }
}

