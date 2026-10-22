<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ModelPredictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prediction_label' => $this->prediction_label,
            'confidence_score' => $this->confidence_score,
            'probability' => $this->probability,
            'model_provider' => $this->model_provider,
            'model_name' => $this->model_name,
            'algorithm_version' => $this->algorithm_version,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
