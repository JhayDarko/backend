<?php

namespace Tests\Unit\Services\Search;

use App\Services\Search\SourceDomainPolicy;
use App\Services\Source\SourceAnalysisService;
use Tests\TestCase;

class SourceDomainPolicyTest extends TestCase
{
    private SourceDomainPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['search.trusted_only' => false]);
        $this->policy = new SourceDomainPolicy(new SourceAnalysisService());
    }

    public function test_it_blocks_social_networks_and_their_subdomains(): void
    {
        foreach ([
            'https://www.facebook.com/post/1',
            'https://m.facebook.com/post/1',
            'https://x.com/user/status/1',
            'https://twitter.com/user/status/1',
            'https://www.youtube.com/watch?v=1',
            'https://youtu.be/abc',
            'https://old.reddit.com/r/a',
            'https://t.me/canal',
        ] as $url) {
            $this->assertTrue($this->policy->isBlocked($url), $url);
        }
    }

    public function test_it_does_not_block_lookalike_domains(): void
    {
        $this->assertFalse($this->policy->isBlocked('https://www.netflix.com/co'));
        $this->assertFalse($this->policy->isBlocked('https://www.eltiempo.com/mundo'));
    }

    public function test_it_ranks_trusted_sources_first_and_drops_blocked(): void
    {
        $ranked = $this->policy->filterAndRank([
            ['url' => 'https://blogdesconocido.example/nota'],
            ['url' => 'https://www.facebook.com/post'],
            ['url' => 'https://www.nasa.gov/mission'],
            ['url' => 'https://www.efe.com/nota'],
        ]);

        $this->assertSame([
            'https://www.nasa.gov/mission',
            'https://www.efe.com/nota',
            'https://blogdesconocido.example/nota',
        ], array_column($ranked, 'url'));
    }

    public function test_trusted_only_mode_keeps_only_credible_sources(): void
    {
        config(['search.trusted_only' => true]);

        $ranked = $this->policy->filterAndRank([
            ['url' => 'https://blogdesconocido.example/nota'],
            ['url' => 'https://www.reuters.com/world'],
        ]);

        $this->assertSame(['https://www.reuters.com/world'], array_column($ranked, 'url'));
    }
}
