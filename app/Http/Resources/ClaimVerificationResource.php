<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClaimVerificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'relation' => $this->relation,
            'status' => $this->status,
            'query_used' => $this->query_used,
            'publisher' => $this->publisher,
            'publisher_site' => $this->publisher_site,
            'raw_rating' => $this->raw_rating,
            'normalized_rating' => $this->normalized_rating,
            'confidence' => $this->confidence,
            'relevance_score' => $this->relevance_score,
            'url' => $this->fact_check_url,
            'title' => $this->fact_check_title,
            'review_date' => $this->review_date,
            'explanation' => $this->explanation,
            'metadata' => $this->metadata,
            'evidences' => EvidenceResource::collection($this->whenLoaded('evidences')),
            'created_at' => $this->created_at,
        ];
    }
}
