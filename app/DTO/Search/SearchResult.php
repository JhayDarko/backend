<?php

namespace App\DTO\Search;

final class SearchResult
{
    public function __construct(
        public readonly string $query,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $snippet,
        public readonly ?string $domain,
        public readonly ?string $publishedAt,
        public readonly ?string $sourceName,
        public readonly string $provider,
        public readonly ?float $relevance = null,
        public readonly array $metadata = [],
    ) {}
}
