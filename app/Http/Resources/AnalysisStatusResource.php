<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight JSON resource for frontend polling of analysis status.
 */
final class AnalysisStatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $claimsCount = $this->claims()->count();
        $hasScore = $this->score()->exists();
        $hasPredictions = $this->predictions()->exists();

        $step = match ($this->status) {
            'pending' => 'queued',
            'processing' => $claimsCount === 0 ? 'extracting_claims' : ($hasScore ? 'finalizing' : 'verifying_evidence'),
            'completed' => 'completed',
            'failed' => 'failed',
            default => $this->status,
        };

        $progress = match ($this->status) {
            'pending' => 0,
            'processing' => $claimsCount === 0 ? 20 : ($hasScore ? 90 : ($hasPredictions ? 75 : 60)),
            'completed' => 100,
            'failed' => $claimsCount === 0 ? 20 : ($hasPredictions ? 75 : 60),
            default => null,
        };

        $duration = null;
        if ($this->started_at && $this->completed_at) {
            $duration = $this->started_at->diffInSeconds($this->completed_at);
        } elseif ($this->started_at) {
            $duration = $this->started_at->diffInSeconds(now());
        }

        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'status' => $this->status,
            'step' => $step,
            'progress' => $progress,
            'progress_details' => [
                'claims_count' => $claimsCount,
                'has_score' => $hasScore,
                'has_predictions' => $hasPredictions,
            ],
            'final_verdict' => $this->final_verdict,
            'credibility_score' => $this->credibility_score,
            'confidence_score' => $this->confidence_score,
            'error' => $this->error,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'elapsed_seconds' => $duration,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
