<?php

namespace App\Services\AI\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, ClaimData>
 */
final class ExtractedClaimsResponse implements Countable, IteratorAggregate
{
    /**
     * @param  array<ClaimData>  $claims
     * @param  array<EntityData>  $entities
     * @param  array<SourceData>  $sources
     * @param  array<string>  $redFlags
     * @param  array<string, mixed>  $rawData
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $claims,
        public readonly array $entities,
        public readonly array $sources,
        public readonly array $redFlags,
        public readonly array $rawData = [],
    ) {}

    /**
     * Create from API response array.
     *
     * @param array{
     *     summary: string,
     *     claims: array<array{text: string, importance: float, verifiable: bool, type: string, context: string}>,
     *     entities: array<array{name: string, type: string}>,
     *     sources: array<array{name: string, url?: string}>,
     *     red_flags: array<string>
     * } $data
     */
    public static function from(array $data): self
    {
        $claims = array_map(
            fn (array $claim) => ClaimData::from($claim),
            $data['claims'] ?? []
        );

        $entities = array_map(
            fn (array $entity) => EntityData::from($entity),
            $data['entities'] ?? []
        );

        $sources = array_map(
            fn (array $source) => SourceData::from($source),
            $data['sources'] ?? []
        );

        return new self(
            summary: (string) ($data['summary'] ?? ''),
            claims: $claims,
            entities: $entities,
            sources: $sources,
            redFlags: $data['red_flags'] ?? [],
            rawData: $data,
        );
    }

    public function hasForbiddenKeys(): bool
    {
        return isset($this->rawData['verdict']) || isset($this->rawData['credibility_score']);
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->claims);
    }

    public function count(): int
    {
        return count($this->claims);
    }
}
