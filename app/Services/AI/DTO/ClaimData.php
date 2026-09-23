<?php

namespace App\Services\AI\DTO;

final readonly class ClaimData
{
    public function __construct(
        public string $text,
        public float $importance,
        public bool $verifiable,
        public string $type,
        public string $context,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            text: (string) $data['text'],
            importance: (float) $data['importance'],
            verifiable: (bool) $data['verifiable'],
            type: (string) $data['type'],
            context: (string) $data['context'],
        );
    }
}
