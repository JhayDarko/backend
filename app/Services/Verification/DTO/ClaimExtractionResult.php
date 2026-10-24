<?php

namespace App\Services\Verification\DTO;

final class ClaimExtractionResult
{
    /**
     * @param  array<int, array{text: string, importance: float, verifiable: bool, type: string, context: string}>  $claims
     * @param  array<int, array{name: string, type: string}>  $entities
     * @param  array<int, array{name: string, url?: string|null}>  $sources
     * @param  array<int, string>  $redFlags
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $claims,
        public readonly array $entities,
        public readonly array $sources,
        public readonly array $redFlags,
        public readonly string $promptVersion,
        public readonly ?string $suggestedTitle = null,
    ) {}

    /**
     * @return array{
     *   summary: string,
     *   claims: array<int, array{text: string, importance: float, verifiable: bool, type: string, context: string}>,
     *   entities: array<int, array{name: string, type: string}>,
     *   sources: array<int, array{name: string, url?: string|null}>,
     *   red_flags: array<int, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'claims' => $this->claims,
            'entities' => $this->entities,
            'sources' => $this->sources,
            'red_flags' => $this->redFlags,
        ];
    }

    /**
     * @return array{
     *   summary: string,
     *   claims: array<int, array{text: string, importance: float, verifiable: bool, type: string, context: string}>,
     *   entities: array<int, array{name: string, type: string}>,
     *   sources: array<int, array{name: string, url?: string|null}>,
     *   red_flags: array<int, string>,
     *   prompt_version: string
     * }
     */
    public function toPersistencePayload(): array
    {
        return [
            'summary' => $this->summary,
            'claims' => $this->claims,
            'entities' => $this->entities,
            'sources' => $this->sources,
            'red_flags' => $this->redFlags,
            'prompt_version' => $this->promptVersion,
        ];
    }
}
