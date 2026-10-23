<?php

namespace App\Services\Evaluation;

use App\Contracts\Search\SearchProviderInterface;

final class FakeEvaluationSearchProvider implements SearchProviderInterface
{
    public function search(string $query, array $options = []): array
    {
        return [[
            'provider' => $this->getName(),
            'title' => 'Simulated evidence',
            'url' => 'https://evidence.example.test/review?key=EVAL_SECRET_SHOULD_NOT_PERSIST',
            'snippet' => 'Fake evidence for the evaluation smoke test.',
            'source_name' => 'Evaluation Fake',
            'relevance' => 0.9,
            'metadata' => [
                'request_headers' => ['Authorization' => 'EVAL_AUTH_SHOULD_NOT_PERSIST'],
                'response_headers' => [
                    'Authorization' => 'EVAL_RESPONSE_AUTH_SHOULD_NOT_PERSIST',
                    'x-ratelimit-remaining' => '7',
                ],
                'worker_queue_driver' => config('queue.connections.redis.driver'),
                'worker_queue_connection' => config('queue.connections.redis.connection'),
                'worker_cache_driver' => config('cache.stores.'.config('cache.default').'.driver'),
                'worker_cache_connection' => config('cache.stores.'.config('cache.default').'.connection'),
                'worker_cache_prefix' => config('cache.stores.'.config('cache.default').'.prefix'),
            ],
        ]];
    }

    public function getName(): string
    {
        return 'evaluation_fake_search';
    }
}
