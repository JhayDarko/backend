<?php

namespace App\Services\AI\DTO;

final readonly class EntityData
{
    public function __construct(
        public string $name,
        public string $type,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            type: (string) $data['type'],
        );
    }
}
