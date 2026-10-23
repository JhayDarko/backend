<?php

namespace Tests\Feature\Evaluation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EvalCollectSmokeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    public function test_it_runs_two_cases_in_a_child_worker_and_sanitizes_all_written_artifacts(): void
    {
        $runId = 'smoke-'.bin2hex(random_bytes(4));
        $dbPath = sys_get_temp_dir().'\\'.$runId.'.sqlite';
        $datasetPath = sys_get_temp_dir().'\\'.$runId.'.jsonl';
        $runDirectory = base_path('evaluation/runs/'.$runId);
        $originalDatabaseDefault = config('database.default');
        $originalCacheDefault = config('cache.default');
        $originalQueueDefault = config('queue.default');
        $originalQueueRedis = config('queue.connections.redis');
        $originalBatchDatabase = config('queue.batching.database');
        $originalFailedDatabase = config('queue.failed.database');
        $productionCache = Cache::store($originalCacheDefault);
        $productionCircuitKey = 'gemini:model-unavailable:evaluation-fake';
        $productionCache->forget($productionCircuitKey);

        $this->setEnv('EVAL_DB_CONNECTION', 'sqlite');
        $this->setEnv('EVAL_DB_DATABASE', $dbPath);
        $this->setEnv('EVAL_FAKE_PROVIDERS', '1');
        $this->setEnv('EVAL_TEST_FAST_BACKOFF', '1');
        config([
            'evaluation.database.connection' => 'sqlite',
            'evaluation.database.database' => $dbPath,
            'evaluation.fake_providers' => '1',
            'evaluation.test_fast_backoff' => '1',
            'database.connections.evaluation.driver' => 'sqlite',
            'database.connections.evaluation.database' => $dbPath,
            'cache.stores.evaluation.connection' => 'evaluation',
            'queue.connections.evaluation.connection' => 'evaluation',
        ]);

        $cases = [
            $this->case('success-case', 'EVAL_BERT_FAIL. Texto local de prueba para evaluar una afirmación verificable.'),
            $this->case('failed-case', 'EVAL_RETRY_EXHAUST: provocar agotamiento de reintentos en proveedor falso.'),
        ];
        file_put_contents($datasetPath, implode(PHP_EOL, array_map(
            fn (array $case): string => json_encode($case, JSON_THROW_ON_ERROR),
            $cases,
        )).PHP_EOL);

        try {
            Http::preventStrayRequests();
            $this->artisan('eval:collect', [
                '--dataset' => $datasetPath,
                '--run-id' => $runId,
                '--mode' => 'production',
                '--no-factcheck' => true,
                '--case-timeout' => 60,
                '--drain-timeout' => 60,
            ])->assertExitCode(1);

            $successPath = $runDirectory.'/production/cases/success-case.json';
            $failurePath = $runDirectory.'/production/cases/failed-case.json';
            $manifestPath = $runDirectory.'/production/manifest.json';
            $success = json_decode((string) file_get_contents($successPath), true, 512, JSON_THROW_ON_ERROR);
            $failure = json_decode((string) file_get_contents($failurePath), true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('completed', $success['status'], json_encode($success, JSON_PRETTY_PRINT));
            $this->assertSame('failed', $failure['status']);
            $this->assertSame('production', $success['mode']);
            $this->assertArrayNotHasKey('expected_verdict', $success);
            $this->assertSame('production', $manifest['mode']);
            $this->assertSame($success['flags'], $manifest['cases']['success-case']['flags']);
            $this->assertContains('ClassifyArticleJob', $success['jobs_exhausted']);
            $this->assertContains('ExtractClaimsJob', $failure['jobs_exhausted']);
            $this->assertTrue(data_get($success, 'analysis.metadata.classifier_unavailable'));
            $this->assertNotContains('VerifyClaimJob', array_keys($success['job_attempts']));
            $this->assertSame(4, $failure['job_attempts']['ExtractClaimsJob']);
            $this->assertContains('ExtractClaimsJob', $failure['jobs_exhausted']);
            $this->assertSame([
                'x-ratelimit-limit' => null,
                'x-ratelimit-remaining' => '7',
                'x-ratelimit-reset' => null,
                'retry-after' => null,
            ], $success['response_headers']);
            $this->assertSame('database', data_get($success, 'analysis.claims.0.claim_verifications.0.metadata.worker_queue_driver'));
            $this->assertSame('evaluation', data_get($success, 'analysis.claims.0.claim_verifications.0.metadata.worker_queue_connection'));
            $this->assertSame('database', data_get($success, 'analysis.claims.0.claim_verifications.0.metadata.worker_cache_driver'));
            $this->assertSame('evaluation', data_get($success, 'analysis.claims.0.claim_verifications.0.metadata.worker_cache_connection'));
            $this->assertSame('eval:'.$runId.':production:', data_get($success, 'analysis.claims.0.claim_verifications.0.metadata.worker_cache_prefix'));
            $this->assertFileExists($manifestPath);
            $this->assertGreaterThanOrEqual(2, DB::connection('evaluation')->table('failed_jobs')->count());

            $this->assertTrue(Cache::store('evaluation')->has('gemini:model-unavailable:evaluation-fake'));
            $this->assertFalse($productionCache->has($productionCircuitKey));

            $sensitiveMarkers = [
                'EVAL_SECRET_SHOULD_NOT_PERSIST',
                'EVAL_AUTH_SHOULD_NOT_PERSIST',
                'EVAL_RESPONSE_AUTH_SHOULD_NOT_PERSIST',
                'EVAL_FAILED_URL_SECRET',
                'EVAL_FAILED_AUTH_SECRET',
            ];
            foreach (File::allFiles($runDirectory) as $file) {
                $contents = $file->getContents();
                foreach ($sensitiveMarkers as $marker) {
                    $this->assertStringNotContainsString($marker, $contents, $file->getRelativePathname());
                }
            }
            $databaseContents = file_get_contents($dbPath) ?: '';
            foreach ($sensitiveMarkers as $marker) {
                $this->assertStringNotContainsString($marker, $databaseContents, 'evaluation database');
            }
        } finally {
            DB::purge('evaluation');
            config([
                'database.default' => $originalDatabaseDefault,
                'cache.default' => $originalCacheDefault,
                'queue.default' => $originalQueueDefault,
                'queue.connections.redis' => $originalQueueRedis,
                'queue.batching.database' => $originalBatchDatabase,
                'queue.failed.database' => $originalFailedDatabase,
            ]);
            DB::setDefaultConnection($originalDatabaseDefault);
            $this->restoreEnvironment();
            File::deleteDirectory($runDirectory);
            @unlink($datasetPath);
            @unlink($dbPath);
        }
    }

    private function case(string $id, string $hint): array
    {
        return [
            'id' => $id,
            'split' => 'dev',
            'input_type' => 'text',
            'url' => null,
            'input_text_hint' => $hint,
            'topic' => 'E2E queue smoke',
            'expected_verdict' => 'credible',
            'excluded_domains' => [],
            'example' => false,
        ];
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environment)) {
            $this->environment[$name] = getenv($name);
        }
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            $this->setConfigurationValue($name, null);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        $this->setConfigurationValue($name, $value);
    }

    private function setConfigurationValue(string $name, ?string $value): void
    {
        $key = match ($name) {
            'EVAL_DB_CONNECTION' => 'evaluation.database.connection',
            'EVAL_DB_DATABASE' => 'evaluation.database.database',
            'EVAL_FAKE_PROVIDERS' => 'evaluation.fake_providers',
            'EVAL_TEST_FAST_BACKOFF' => 'evaluation.test_fast_backoff',
            default => null,
        };
        if ($key !== null) {
            config([$key => $value]);
        }
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->environment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
