<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'type' => $this->type,
            'importance' => $this->importance,
            'verifiable' => $this->verifiable,
            'context' => $this->context,
            'fingerprint' => $this->fingerprint,
            'metadata' => $this->metadata,
            'verifications' => ClaimVerificationResource::collection($this->whenLoaded('claimVerifications')),
            'created_at' => $this->created_at,
        ];
    }
}
