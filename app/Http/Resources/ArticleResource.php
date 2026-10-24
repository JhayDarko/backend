<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'canonical_url' => $this->canonical_url,
            'title' => $this->title,
            'title_source' => data_get($this->resource, 'title_source'),
            'description' => $this->description,
            'content' => $this->content,
            'author_name' => $this->author_name,
            'published_at' => $this->published_at,
            'language' => $this->language,
            'source' => new SourceResource($this->whenLoaded('source')),
            'created_at' => $this->created_at,
        ];
    }
}
