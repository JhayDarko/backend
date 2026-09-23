<?php

namespace Tests\Unit\Services\AI\DTO;

use App\Services\AI\DTO\ClaimData;
use App\Services\AI\DTO\EntityData;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use App\Services\AI\DTO\SourceData;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractedClaimsResponseTest extends TestCase
{
    #[Test]
    public function it_creates_from_array(): void
    {
        $data = [
            'summary' => 'Test article.',
            'claims' => [
                [
                    'text' => 'Claim 1',
                    'importance' => 0.8,
                    'verifiable' => true,
                    'type' => 'factual',
                    'context' => 'Context 1',
                ],
                [
                    'text' => 'Claim 2',
                    'importance' => 0.5,
                    'verifiable' => false,
                    'type' => 'opinion',
                    'context' => 'Context 2',
                ],
            ],
            'entities' => [
                ['name' => 'John Doe', 'type' => 'person'],
                ['name' => 'USA', 'type' => 'location'],
            ],
            'sources' => [
                ['name' => 'Reuters', 'url' => 'https://reuters.com'],
                ['name' => 'AP News'],
            ],
            'red_flags' => ['Potential bias', 'Unverified sources'],
        ];

        $response = ExtractedClaimsResponse::from($data);

        $this->assertSame('Test article.', $response->summary);
        $this->assertCount(2, $response->claims);
        $this->assertCount(2, $response->entities);
        $this->assertCount(2, $response->sources);
        $this->assertCount(2, $response->redFlags);
    }

    #[Test]
    public function it_creates_claim_data_from_array(): void
    {
        $claimArray = [
            'text' => 'Test claim',
            'importance' => 0.75,
            'verifiable' => true,
            'type' => 'factual',
            'context' => 'Test context',
        ];

        $claim = ClaimData::from($claimArray);

        $this->assertSame('Test claim', $claim->text);
        $this->assertSame(0.75, $claim->importance);
        $this->assertTrue($claim->verifiable);
        $this->assertSame('factual', $claim->type);
        $this->assertSame('Test context', $claim->context);
    }

    #[Test]
    public function it_creates_entity_data_from_array(): void
    {
        $entityArray = [
            'name' => 'John Doe',
            'type' => 'person',
        ];

        $entity = EntityData::from($entityArray);

        $this->assertSame('John Doe', $entity->name);
        $this->assertSame('person', $entity->type);
    }

    #[Test]
    public function it_creates_source_data_from_array(): void
    {
        $sourceArray = [
            'name' => 'Reuters',
            'url' => 'https://reuters.com',
        ];

        $source = SourceData::from($sourceArray);

        $this->assertSame('Reuters', $source->name);
        $this->assertSame('https://reuters.com', $source->url);
    }

    #[Test]
    public function it_creates_source_without_url(): void
    {
        $sourceArray = [
            'name' => 'Unknown Source',
        ];

        $source = SourceData::from($sourceArray);

        $this->assertSame('Unknown Source', $source->name);
        $this->assertNull($source->url);
    }

    #[Test]
    public function it_is_iterable(): void
    {
        $data = [
            'summary' => 'Test.',
            'claims' => [
                [
                    'text' => 'Claim 1',
                    'importance' => 0.5,
                    'verifiable' => true,
                    'type' => 'factual',
                    'context' => 'Context',
                ],
                [
                    'text' => 'Claim 2',
                    'importance' => 0.6,
                    'verifiable' => true,
                    'type' => 'factual',
                    'context' => 'Context',
                ],
            ],
            'entities' => [],
            'sources' => [],
            'red_flags' => [],
        ];

        $response = ExtractedClaimsResponse::from($data);

        $claims = [];
        foreach ($response as $claim) {
            $claims[] = $claim->text;
        }

        $this->assertSame(['Claim 1', 'Claim 2'], $claims);
    }

    #[Test]
    public function it_is_countable(): void
    {
        $data = [
            'summary' => 'Test.',
            'claims' => [
                [
                    'text' => 'Claim 1',
                    'importance' => 0.5,
                    'verifiable' => true,
                    'type' => 'factual',
                    'context' => 'Context',
                ],
                [
                    'text' => 'Claim 2',
                    'importance' => 0.6,
                    'verifiable' => true,
                    'type' => 'factual',
                    'context' => 'Context',
                ],
            ],
            'entities' => [],
            'sources' => [],
            'red_flags' => [],
        ];

        $response = ExtractedClaimsResponse::from($data);

        $this->assertCount(2, $response);
    }

    #[Test]
    public function it_handles_empty_arrays(): void
    {
        $data = [
            'summary' => 'Empty article.',
            'claims' => [],
            'entities' => [],
            'sources' => [],
            'red_flags' => [],
        ];

        $response = ExtractedClaimsResponse::from($data);

        $this->assertCount(0, $response->claims);
        $this->assertCount(0, $response->entities);
        $this->assertCount(0, $response->sources);
        $this->assertCount(0, $response->redFlags);
    }

    #[Test]
    public function it_handles_missing_optional_fields(): void
    {
        $data = [
            'summary' => 'Test.',
            // claims, entities, sources, red_flags omitted
        ];

        $response = ExtractedClaimsResponse::from($data);

        $this->assertSame('Test.', $response->summary);
        $this->assertCount(0, $response->claims);
        $this->assertCount(0, $response->entities);
        $this->assertCount(0, $response->sources);
        $this->assertCount(0, $response->redFlags);
    }

    #[Test]
    public function it_preserves_all_claim_types(): void
    {
        $types = ['factual', 'statistical', 'temporal', 'causal', 'attribution', 'quote', 'prediction', 'opinion'];

        $claims = [];
        foreach ($types as $i => $type) {
            $claims[] = [
                'text' => "Claim of type $type",
                'importance' => 0.5,
                'verifiable' => true,
                'type' => $type,
                'context' => 'Context',
            ];
        }

        $data = [
            'summary' => 'Test.',
            'claims' => $claims,
            'entities' => [],
            'sources' => [],
            'red_flags' => [],
        ];

        $response = ExtractedClaimsResponse::from($data);

        $this->assertCount(8, $response->claims);

        foreach ($response->claims as $i => $claim) {
            $this->assertSame($types[$i], $claim->type);
        }
    }
}
