<?php

namespace Tests\Unit\Services\Source;

use App\Models\Source;
use App\Services\Source\SourceAnalysisService;
use Tests\TestCase;

class SourceAnalysisServiceTest extends TestCase
{
    private SourceAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SourceAnalysisService();
    }

    public function test_identifies_known_fact_checker_domain(): void
    {
        $result = $this->service->analyze(null, 'https://chequeado.com/verificacion/nota-123');

        $this->assertTrue($result->isKnownFactChecker);
        $this->assertSame('fact_checker', $result->trustTier);
        $this->assertSame('chequeado.com', $result->domain);
        $this->assertGreaterThanOrEqual(0.90, $result->reputationScore);
        $this->assertContains('verified_fact_checking_organization', $result->flags);
    }

    public function test_identifies_subdomain_fact_checker(): void
    {
        $result = $this->service->analyze(null, 'https://factual.afp.com/doc.afp.com.36V734T');

        $this->assertTrue($result->isKnownFactChecker);
        $this->assertSame('fact_checker', $result->trustTier);
        $this->assertGreaterThanOrEqual(0.90, $result->reputationScore);
    }

    public function test_identifies_government_or_academic_tld(): void
    {
        $result = $this->service->analyze(null, 'https://www.minsalud.gov.co/noticias');

        $this->assertFalse($result->isKnownFactChecker);
        $this->assertSame('academic_gov', $result->trustTier);
        $this->assertSame('minsalud.gov.co', $result->domain);
        $this->assertGreaterThanOrEqual(0.85, $result->reputationScore);
        $this->assertContains('institutional_tld_detected', $result->flags);
    }

    public function test_identifies_mainstream_news_media(): void
    {
        $result = $this->service->analyze(null, 'https://elpais.com/internacional/articulo.html');

        $this->assertFalse($result->isKnownFactChecker);
        $this->assertSame('mainstream_press', $result->trustTier);
        $this->assertSame('elpais.com', $result->domain);
        $this->assertGreaterThanOrEqual(0.80, $result->reputationScore);
        $this->assertContains('known_news_outlet', $result->flags);
    }

    public function test_handles_unknown_unverified_domain(): void
    {
        $result = $this->service->analyze(null, 'https://noticias-desconocidas-xyz123.com/post');

        $this->assertFalse($result->isKnownFactChecker);
        $this->assertSame('unverified_domain', $result->trustTier);
        $this->assertSame(0.50, $result->reputationScore);
        $this->assertContains('unverified_publisher_domain', $result->flags);
    }

    public function test_respects_explicit_source_reputation_score(): void
    {
        $source = new Source([
            'name' => 'Custom Source',
            'domain' => 'customsite.org',
            'reputation_score' => 0.77,
            'type' => 'news',
        ]);

        $result = $this->service->analyze($source);

        $this->assertSame(0.77, $result->reputationScore);
        $this->assertContains('source_explicit_reputation', $result->flags);
    }

    public function test_handles_empty_source_and_url_gracefully(): void
    {
        $result = $this->service->analyze();

        $this->assertSame('unknown', $result->domain);
        $this->assertSame('unknown', $result->trustTier);
        $this->assertSame(0.50, $result->reputationScore);
        $this->assertContains('no_domain_provided', $result->flags);
    }
}

