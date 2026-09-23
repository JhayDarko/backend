<?php

namespace App\Services\AI\DTO;

final readonly class SourceData
{
    public function __construct(
        public string $name,
        public ?string $url = null,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            url: isset($data['url']) ? (string) $data['url'] : null,
        );
    }
}
