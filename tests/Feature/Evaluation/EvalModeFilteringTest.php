<?php

namespace Tests\Feature\Evaluation;

use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Services\FactCheck\FactCheckService;
use App\Services\Search\SourceDomainPolicy;
use Tests\TestCase;

final class EvalModeFilteringTest extends TestCase
{
    public function test_blind_mode_excludes_fact_check_by_publisher_and_result_host(): void
    {
        config([
            'evaluation.active' => true,
            'evaluation.mode' => 'blind',
            'evaluation.no_factcheck' => false,
            'evaluation.excluded_domains' => ['blocked.example'],
        ]);
        $claim = $this->claim();
        $this->app->instance(FactCheckProviderInterface::class, new class implements FactCheckProviderInterface
        {
            public function search(string $query): array
            {
                return [
                    $this->result('publisher-blocked', 'blocked.example', 'https://good.example/review/1'),
                    $this->result('host-blocked', 'good.example', 'https://sub.blocked.example/review/2'),
                    $this->result('accepted', 'good.example', 'https://good.example/review/3'),
                ];
            }

            private function result(string $title, string $publisherSite, string $url): array
            {
                return [
                    'provider' => 'google_fact_check',
                    'query' => 'Test query',
                    'publisher' => 'Test Publisher',
                    'publisher_site' => $publisherSite,
                    'fact_check_url' => $url,
                    'fact_check_title' => $title,
                    'raw_rating' => 'True',
                    'normalized_rating' => 'true',
                    'metadata' => [],
                ];
            }

            public function getName(): string
            {
                return 'google_fact_check';
            }
        });

        $result = app(FactCheckService::class)->verifyClaim($claim);

        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $claim->fresh()->claimVerifications()->count());
        $this->assertSame(
            'https://good.example/review/3',
            $claim->fresh()->claimVerifications()->first()->fact_check_url,
        );
    }

    public function test_blind_mode_adds_case_exclusions_to_search_blocklist(): void
    {
        config([
            'evaluation.active' => true,
            'evaluation.mode' => 'blind',
            'evaluation.excluded_domains' => ['blocked.example'],
        ]);

        $policy = app(SourceDomainPolicy::class);

        $this->assertContains('blocked.example', $policy->blockedDomains());
        $this->assertTrue($policy->isExcluded('https://news.blocked.example/story'));
        $this->assertFalse($policy->isExcluded('https://good.example/story'));
    }

    public function test_no_factcheck_disables_only_the_fact_check_stage(): void
    {
        config([
            'evaluation.active' => true,
            'evaluation.mode' => 'production',
            'evaluation.no_factcheck' => true,
        ]);
        $claim = $this->claim();
        $this->app->instance(FactCheckProviderInterface::class, new class implements FactCheckProviderInterface
        {
            public function search(string $query): array
            {
                throw new \RuntimeException('Fact-check should not run.');
            }

            public function getName(): string
            {
                return 'test';
            }
        });

        $this->assertSame(['status' => 'disabled', 'results' => []], app(FactCheckService::class)->verifyClaim($claim));
        $this->assertSame(0, $claim->fresh()->claimVerifications()->count());
    }

    private function claim(): Claim
    {
        $article = Article::query()->create([
            'url' => 'https://article.example.test/story',
            'title' => 'Evaluation test',
            'content' => 'Test content.',
            'language' => 'en',
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'status' => 'processing',
        ]);

        return Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'A verifiable test claim.',
            'type' => 'factual',
            'importance' => 0.8,
            'verifiable' => true,
            'context' => 'Context.',
            'fingerprint' => 'eval-'.bin2hex(random_bytes(8)),
        ]);
    }
}
