<?php

namespace Tests\Unit\Services\Evaluation;

use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use Tests\TestCase;

final class EvaluationSnapshotSanitizerTest extends TestCase
{
    public function test_it_removes_secrets_and_keeps_only_rate_limit_response_headers(): void
    {
        $sanitized = app(EvaluationSnapshotSanitizer::class)->sanitize([
            'url' => 'https://example.test/check?key=TOP_SECRET&source=unit',
            'credential_url' => 'https://user:password@example.test/check',
            'request_headers' => ['Authorization' => 'Bearer TOP_SECRET'],
            'response_headers' => [
                'Authorization' => 'Bearer TOP_SECRET',
                'x-ratelimit-limit' => ['100'],
                'x-ratelimit-remaining' => ['80'],
                'Retry-After' => ['3'],
                'Content-Type' => ['application/json'],
            ],
        ]);
        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('TOP_SECRET', $encoded);
        $this->assertStringNotContainsString('user:password', $encoded);
        $this->assertStringContainsString('source=unit', $encoded);
        $this->assertSame([
            'x-ratelimit-limit' => '100',
            'x-ratelimit-remaining' => '80',
            'retry-after' => '3',
        ], $sanitized['response_headers']);
    }
}
