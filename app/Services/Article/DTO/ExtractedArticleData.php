<?php

namespace App\Services\Article\DTO;

use DateTimeImmutable;

final readonly class ExtractedArticleData
{
    public function __construct(
        public string $url,
        public ?string $canonicalUrl,
        public string $title,
        public ?string $description,
        public ?string $content,
        public ?string $authorName,
        public ?DateTimeImmutable $publishedAt,
        public ?string $language,
        public array $metadata,
    ) {}
}
