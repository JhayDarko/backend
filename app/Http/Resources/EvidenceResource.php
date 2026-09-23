<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'domain' => $this->metadata['domain'] ?? null,
            'source_name' => $this->metadata['source_name'] ?? null,
            'published_at' => $this->published_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
