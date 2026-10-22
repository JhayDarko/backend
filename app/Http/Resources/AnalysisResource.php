<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AnalysisResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'status' => $this->status,
            'algorithm_version' => $this->algorithm_version,
            'credibility_score' => $this->credibility_score,
            'confidence_score' => $this->confidence_score,
            'final_verdict' => $this->final_verdict,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'article' => new ArticleResource($this->whenLoaded('article')),
            'score' => new ScoreResource($this->whenLoaded('score')),
            'predictions' => ModelPredictionResource::collection($this->whenLoaded('predictions')),
            'claims' => ClaimResource::collection($this->whenLoaded('claims')),
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
