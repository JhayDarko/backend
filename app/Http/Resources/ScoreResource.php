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
            'created_at' => $this->created_at,
        ];
    }
}

