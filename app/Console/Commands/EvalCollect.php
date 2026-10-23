<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use App\Services\Article\ArticleIngestionService;
use App\Services\Evaluation\EvaluationRuntime;
use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class EvalCollect extends Command
{
    protected $signature = 'eval:collect
        {--dataset= : Archivo JSONL del dataset}
        {--split= : Limita a dev o test}
        {--case= : Procesa únicamente este id de caso}
        {--limit= : Máximo de casos}
        {--resume : Omite instantáneas completas}
        {--dry-run : Estima llamadas sin DB, descargas ni solicitudes HTTP}
        {--max-cases-per-minute= : Límite de inicio de casos}
        {--run-id= : Identificador reproducible de corrida}
        {--mode=production : Modo production o blind}
        {--no-factcheck : Desactiva la etapa Google Fact Check}
        {--case-timeout=1800 : Tiempo máximo por caso en segundos}
        {--drain-timeout=3600 : Espera máxima acumulada para drenar cola y batches}';

    protected $description = 'Recolecta observaciones del pipeline en un entorno de evaluación aislado';

    /** @var array<string, mixed> */
    private array $manifest = [];

    public function handle(EvaluationRuntime $runtime, EvaluationSnapshotSanitizer $sanitizer): int
    {
        $dataset = $this->resolvePath($this->option('dataset') ?: 'evaluation/dataset/cases.jsonl');
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['production', 'blind'], true)) {
            $this->error('--mode debe ser production o blind.');

            return self::FAILURE;
        }

        if (! is_file($dataset)) {
            $this->error("No existe el dataset: {$dataset}");

            return self::FAILURE;
        }

        $cases = $this->readCases($dataset);
        if ($cases === null) {
            return self::FAILURE;
        }

        $cases = array_values(array_filter($cases, fn (array $case): bool => ($case['example'] ?? false) !== true));
        if ($this->option('split') !== null) {
            $cases = array_values(array_filter($cases, fn (array $case): bool => ($case['split'] ?? null) === $this->option('split')));
        }
        if ($this->option('case') !== null) {
            $cases = array_values(array_filter($cases, fn (array $case): bool => ($case['id'] ?? null) === $this->option('case')));
        }
        usort($cases, fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));
        if ($this->option('limit') !== null) {
            $cases = array_slice($cases, 0, max(0, (int) $this->option('limit')));
        }
        if ($cases === []) {
            $this->warn('No hay casos que coincidan con los filtros.');

            return self::SUCCESS;
        }

        $runId = (string) ($this->option('run-id') ?: now()->format('YmdHis').'-'.bin2hex(random_bytes(4)));
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $runId) !== 1) {
            $this->error('--run-id solo admite letras, números, punto, guion y guion bajo (máximo 100).');

            return self::FAILURE;
        }

        $flags = [
            'mode' => $mode,
            'no_factcheck' => (bool) $this->option('no-factcheck'),
            'factcheck_enabled' => ! (bool) $this->option('no-factcheck'),
            'excluded_domains_applied' => $mode === 'blind',
            'resume' => (bool) $this->option('resume'),
        ];

        if ($this->option('dry-run')) {
            $this->info('Casos: '.count($cases));
            $factCheckEstimate = $this->option('no-factcheck') ? '0' : 'hasta 20';
            $this->info("Solicitudes nominales por caso (hasta 10 claims): 1 extracción Gemini, 1 síntesis Gemini, 1 BERT, hasta 10 búsquedas web y {$factCheckEstimate} consultas Google Fact Check.");
            $this->info('costo pendiente: tokens y límites que no reporte el proveedor serán null.');
            $this->line('Configuración: '.json_encode(['run_id' => $runId, 'flags' => $flags, 'case_ids' => array_column($cases, 'id')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        try {
            $runtime->activate();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $runtimeConfig = [
            'evaluation.active' => true,
            'evaluation.mode' => $mode,
            'evaluation.run_id' => $runId,
            'evaluation.no_factcheck' => (bool) $this->option('no-factcheck'),
            'evaluation.excluded_domains' => [],
            'cache.stores.evaluation.prefix' => 'eval:'.$runId.':'.$mode.':',
        ];
        config($runtimeConfig);

        $this->ensureEvaluationDatabaseFile();
        if (! Schema::connection('evaluation')->hasTable('migrations')) {
            $migrationExit = Artisan::call('migrate', ['--database' => 'evaluation', '--force' => true, '--no-interaction' => true]);
            if ($migrationExit !== self::SUCCESS) {
                $this->error('No se pudieron ejecutar las migraciones en la base de evaluación: '.Artisan::output());

                return self::FAILURE;
            }
        } else {
            Artisan::call('migrate', ['--database' => 'evaluation', '--force' => true, '--no-interaction' => true]);
        }

        $runDirectory = base_path('evaluation/runs/'.$runId.'/'.$mode);
        $casesDirectory = $runDirectory.'/cases';
        if (! is_dir($casesDirectory) && ! mkdir($casesDirectory, 0775, true) && ! is_dir($casesDirectory)) {
            $this->error("No se pudo crear el directorio de instantáneas: {$casesDirectory}");

            return self::FAILURE;
        }

        $manifestPath = $runDirectory.'/manifest.json';
        $this->manifest = $this->loadManifest($manifestPath, $runId, $mode, $flags);
        $this->manifest['dataset'] = $this->relativePath($dataset);
        $this->manifest['selected_cases'] = array_column($cases, 'id');
        $this->manifest['cases'] ??= [];

        $failed = 0;
        $intervalSeconds = $this->intervalSeconds();
        $lastStartedAt = null;

        foreach ($cases as $case) {
            $caseId = (string) $case['id'];
            $snapshotPath = $casesDirectory.'/'.$caseId.'.json';
            if ($this->option('resume') && $this->isCompleteSnapshot($snapshotPath)) {
                $this->line("{$caseId}: instantánea completa omitida (--resume).");

                continue;
            }

            if ($intervalSeconds !== null && $lastStartedAt !== null) {
                $wait = $intervalSeconds - (microtime(true) - $lastStartedAt);
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1_000_000));
                }
            }
            $lastStartedAt = microtime(true);

            $caseStart = hrtime(true);
            $caseStartedAt = now()->toIso8601String();
            $caseResult = [];
            try {
                $caseResult = $this->collectCase(
                    $case,
                    $runId,
                    $mode,
                    $snapshotPath,
                    (int) $this->option('case-timeout'),
                    (int) $this->option('drain-timeout'),
                );
            } catch (Throwable $exception) {
                $failed++;
                $caseResult = [
                    'case_id' => $caseId,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }

            $caseResult['case_id'] = $caseId;
            $caseResult['started_at'] = $caseStartedAt;
            $caseResult['finished_at'] = now()->toIso8601String();
            $caseResult['wall_time_ms'] = (int) ((hrtime(true) - $caseStart) / 1_000_000);
            $caseResult['mode'] = $mode;
            $caseResult['flags'] = array_merge($flags, [
                'excluded_domains' => $mode === 'blind' ? array_values((array) ($case['excluded_domains'] ?? [])) : [],
            ]);
            $caseResult = $sanitizer->sanitize($caseResult);
            $this->writeJsonAtomically($snapshotPath, $caseResult);

            $this->manifest['cases'][$caseId] = [
                'mode' => $mode,
                'flags' => $caseResult['flags'],
                'status' => $caseResult['status'],
                'snapshot' => 'cases/'.$caseId.'.json',
                'wall_time_ms' => $caseResult['wall_time_ms'],
                'jobs_exhausted' => $caseResult['jobs_exhausted'] ?? [],
            ];
            $this->manifest['updated_at'] = now()->toIso8601String();
            $this->writeJsonAtomically($manifestPath, $sanitizer->sanitize($this->manifest));

            $this->line("{$caseId}: {$caseResult['status']} ({$caseResult['wall_time_ms']} ms).");
            if ($caseResult['status'] !== 'completed') {
                $failed++;
            }
        }

        $this->manifest['summary'] = [
            'selected' => count($cases),
            'completed' => count(array_filter($this->manifest['cases'], fn (array $entry): bool => $entry['status'] === 'completed')),
            'failed' => count(array_filter($this->manifest['cases'], fn (array $entry): bool => $entry['status'] === 'failed')),
        ];
        $this->manifest['updated_at'] = now()->toIso8601String();
        $this->writeJsonAtomically($manifestPath, $sanitizer->sanitize($this->manifest));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function collectCase(array $case, string $runId, string $mode, string $snapshotPath, int $caseTimeout, int $drainTimeout): array
    {
        if ($caseTimeout < 1 || $drainTimeout < 1) {
            throw new \InvalidArgumentException('--case-timeout y --drain-timeout deben ser positivos.');
        }

        $caseId = (string) $case['id'];
        $user = $this->evaluationUser($runId, $mode);
        $input = $this->resolveInput($case);
        $article = $this->createArticle($case, $input, $user);
        if (($case['input_type'] ?? null) !== 'text') {
            $input['content'] = (string) $article->content;
        }
        $analysis = Analysis::query()->firstOrCreate([
            'article_id' => $article->id,
            'user_id' => $user->id,
        ], [
            'status' => 'pending',
            'metadata' => ['evaluation_case_id' => $caseId, 'evaluation_run_id' => $runId, 'evaluation_mode' => $mode],
        ]);

        $cache = Cache::store('evaluation');
        $cache->forget("job-attempts:{$caseId}:AnalyzeArticleJob");
        $cache->forget("jobs-exhausted:{$caseId}");

        config([
            'evaluation.active' => true,
            'evaluation.mode' => $mode,
            'evaluation.excluded_domains' => $mode === 'blind' ? (array) ($case['excluded_domains'] ?? []) : [],
            'evaluation.no_factcheck' => (bool) $this->option('no-factcheck'),
        ]);

        $failedJobCursor = (int) DB::table('failed_jobs')->max('id');
        AnalyzeArticleJob::dispatch($analysis->id)->onConnection('redis')->onQueue('evaluation');
        $this->drainWorker($analysis, $caseId, $mode, $caseTimeout, $drainTimeout, (array) ($case['excluded_domains'] ?? []));

        $analysis->refresh()->load([
            'article',
            'claims.claimVerifications.evidences',
            'predictions',
            'score',
        ]);
        $attempts = $this->attemptCounts($caseId);
        $exhausted = (array) $cache->get("jobs-exhausted:{$caseId}", []);
        $status = $analysis->status === 'completed' ? 'completed' : 'failed';
        $responseHeaders = $this->collectRateLimitHeaders($analysis->toArray());

        return [
            'status' => $status,
            'input' => [
                'sha256' => hash('sha256', (string) $input['content']),
                'input_source' => $input['source'],
            ],
            'analysis' => [
                'id' => $analysis->id,
                'status' => $analysis->status,
                'error' => $analysis->error,
                'metadata' => $analysis->metadata,
                'score' => $analysis->score?->toArray(),
                'final_verdict' => $analysis->final_verdict,
                'algorithm_version' => $analysis->algorithm_version,
                'article' => [
                    'id' => data_get($analysis, 'article.id'),
                    'url' => data_get($analysis, 'article.url'),
                    'title' => data_get($analysis, 'article.title'),
                    'content_hash' => data_get($analysis, 'article.content_hash'),
                ],
                'claims' => $analysis->claims->map(fn ($claim): array => $claim->toArray())->all(),
                'predictions' => $analysis->predictions->map(fn ($prediction): array => $prediction->toArray())->all(),
            ],
            'response_headers' => $responseHeaders,
            'provider_usage' => $this->collectProviderUsage($analysis->toArray()),
            'job_attempts' => $attempts,
            'jobs_exhausted' => $exhausted,
            'provider_errors' => $this->failedJobExceptions($failedJobCursor),
            'provider' => [
                'name' => $analysis->model_provider,
                'model' => $analysis->model_name,
            ],
            'algorithm_version' => $analysis->algorithm_version,
            'git_commit' => trim((string) @shell_exec('git rev-parse HEAD')),
            'snapshot_path' => $this->relativePath($snapshotPath),
            'analysis_id' => $analysis->id,
        ];
    }

    private function drainWorker(Analysis $analysis, string $caseId, string $mode, int $caseTimeout, int $drainTimeout, array $excludedDomains): void
    {
        $started = microtime(true);
        $caseDeadline = $started + $caseTimeout;
        $drainDeadline = $started + $drainTimeout;
        $batchId = null;
        $timedOut = false;

        do {
            $remaining = min($caseDeadline, $drainDeadline) - microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $environment = [
                'APP_ENV' => app()->environment(),
                'APP_KEY' => (string) config('app.key'),
                'EVAL_PROCESS' => '1',
                'EVAL_MODE' => $mode,
                'EVAL_RUN_ID' => $this->manifest['run_id'],
                'EVAL_CASE_ID' => $caseId,
                'EVAL_LOG_PATH' => base_path('evaluation/runs/'.$this->manifest['run_id'].'/'.$mode.'/evaluation.log'),
                'EVAL_EXCLUDED_DOMAINS' => json_encode($mode === 'blind' ? $excludedDomains : [], JSON_THROW_ON_ERROR),
                'EVAL_NO_FACTCHECK' => $this->option('no-factcheck') ? '1' : '0',
                'EVAL_FAKE_PROVIDERS' => (string) config('evaluation.fake_providers', false),
                'EVAL_TEST_FAST_BACKOFF' => (string) config('evaluation.test_fast_backoff', false),
                'EVAL_DB_CONNECTION' => (string) config('evaluation.database.connection'),
                'EVAL_DB_DATABASE' => (string) config('evaluation.database.database'),
                'EVAL_DB_HOST' => (string) config('evaluation.database.host', ''),
                'EVAL_DB_PORT' => (string) config('evaluation.database.port', ''),
                'EVAL_DB_USERNAME' => (string) config('evaluation.database.username', ''),
                'EVAL_DB_PASSWORD' => (string) config('evaluation.database.password', ''),
            ];
            $process = new Process([
                PHP_BINARY,
                base_path('artisan'),
                'queue:work',
                'redis',
                '--queue=evaluation',
                '--stop-when-empty',
                '--sleep=0',
                '--timeout=190',
                '--tries=4',
            ], base_path(), $environment);
            $process->setTimeout(max(1, $remaining));
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                $timedOut = true;
                break;
            }

            $analysis->refresh();
            $batchId = (string) data_get($analysis->metadata, 'evaluation_batch_id', $batchId);
            $queuedJobs = DB::table('jobs')->where('queue', 'evaluation')->count();
            $batch = $batchId !== ''
                ? DB::table('job_batches')->where('id', $batchId)->first(['pending_jobs', 'failed_jobs', 'finished_at'])
                : null;
            $batchPending = $batch !== null && $batch->pending_jobs > $batch->failed_jobs;
            if ($batch !== null && ! $batchPending && $batch->finished_at === null) {
                DB::table('job_batches')->where('id', $batchId)->update(['finished_at' => now()->timestamp]);
            }
            if ($queuedJobs === 0 && ! $batchPending && in_array($analysis->status, ['completed', 'failed'], true)) {
                return;
            }

            $nextAvailability = DB::table('jobs')->where('queue', 'evaluation')->min('available_at');
            $waitSeconds = is_numeric($nextAvailability)
                ? max(0.1, min(1.0, ((float) $nextAvailability - microtime(true))))
                : 0.2;
            usleep((int) ($waitSeconds * 1_000_000));
        } while (microtime(true) < $caseDeadline && microtime(true) < $drainDeadline);

        if ($timedOut) {
            $reason = microtime(true) >= $caseDeadline
                ? "Case exceeded --case-timeout ({$caseTimeout}s). Worker process terminated."
                : "Queue drain exceeded --drain-timeout ({$drainTimeout}s). Worker process terminated.";
            $analysis->update(['status' => 'failed', 'error' => $reason, 'completed_at' => now()]);
            DB::table('jobs')->where('queue', 'evaluation')->delete();
            if ($batchId !== '') {
                DB::table('job_batches')->where('id', $batchId)->update([
                    'pending_jobs' => 0,
                    'failed_jobs' => DB::raw('total_jobs'),
                    'finished_at' => now()->timestamp,
                ]);
            }

            Log::warning('Evaluation case exceeded its worker deadline.', ['case_id' => $caseId, 'reason' => $reason]);
        }
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{content: string, source: string}
     */
    private function resolveInput(array $case): array
    {
        if (($case['input_type'] ?? null) !== 'text') {
            return ['content' => '', 'source' => 'original'];
        }

        $candidate = $case['text_cache_path'] ?? 'evaluation/cache/'.($case['id'] ?? '').'.txt';
        $path = $this->resolvePath((string) $candidate);
        if (is_file($path)) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("No se pudo leer el texto local del caso {$case['id']}.");
            }

            return ['content' => $content, 'source' => 'original'];
        }

        $hint = trim((string) ($case['input_text_hint'] ?? ''));
        if ($hint === '') {
            throw new \RuntimeException("El caso {$case['id']} no tiene texto local ni input_text_hint.");
        }

        return ['content' => $hint, 'source' => 'hint'];
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function createArticle(array $case, array $input, User $user): Article
    {
        $ingestion = app(ArticleIngestionService::class);
        if (($case['input_type'] ?? null) === 'text') {
            return $ingestion->ingestText(
                content: $input['content'],
                title: (string) ($case['topic'] ?? $case['id']),
                userId: (int) $user->id,
                metadata: ['evaluation_case_id' => $case['id']],
            );
        }

        $url = trim((string) ($case['url'] ?? ''));
        if ($url === '') {
            throw new \RuntimeException("El caso {$case['id']} requiere una URL.");
        }

        return $ingestion->ingest($url, (int) $user->id);
    }

    private function evaluationUser(string $runId, string $mode): User
    {
        $email = 'eval-'.hash('sha256', $runId.'|'.$mode).'@evaluation.invalid';

        return User::query()->firstOrCreate(['email' => $email], [
            'name' => "Evaluation {$runId} {$mode}",
            'password' => Hash::make(bin2hex(random_bytes(32))),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function attemptCounts(string $caseId): array
    {
        $counts = [];
        foreach (['AnalyzeArticleJob', 'ExtractClaimsJob', 'ClassifyArticleJob', 'VerifyClaimJob', 'SearchClaimEvidenceJob', 'FinalizeAnalysisJob'] as $jobName) {
            $attempts = (int) Cache::store('evaluation')->get("job-attempts:{$caseId}:{$jobName}", 0);
            if ($attempts > 0) {
                $counts[$jobName] = $attempts;
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function failedJobExceptions(int $afterId): array
    {
        $failures = DB::table('failed_jobs')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get(['exception']);
        $matching = [];
        foreach ($failures as $failure) {
            $matching[] = strtok((string) $failure->exception, "\n") ?: 'Job failed.';
        }

        return $matching;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function collectRateLimitHeaders(array $data): array
    {
        $headers = [
            'x-ratelimit-limit' => null,
            'x-ratelimit-remaining' => null,
            'x-ratelimit-reset' => null,
            'retry-after' => null,
        ];
        $walk = function (mixed $value) use (&$walk, &$headers): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'response_headers' && is_array($child)) {
                    $headers = array_merge($headers, app(EvaluationSnapshotSanitizer::class)->rateLimitHeaders($child));
                } else {
                    $walk($child);
                }
            }
        };
        $walk($data);

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|null>
     */
    private function collectProviderUsage(array $data): array
    {
        $usage = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];
        $walk = function (mixed $value) use (&$walk, &$usage): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'usageMetadata' && is_array($child)) {
                    $usage['prompt_tokens'] = isset($child['promptTokenCount']) ? (int) $child['promptTokenCount'] : null;
                    $usage['completion_tokens'] = isset($child['candidatesTokenCount']) ? (int) $child['candidatesTokenCount'] : null;
                    $usage['total_tokens'] = isset($child['totalTokenCount']) ? (int) $child['totalTokenCount'] : null;
                } else {
                    $walk($child);
                }
            }
        };
        $walk($data);

        return $usage;
    }

    private function intervalSeconds(): ?float
    {
        $perMinute = $this->option('max-cases-per-minute');
        if ($perMinute === null) {
            return null;
        }
        if (! is_numeric($perMinute) || (float) $perMinute <= 0) {
            throw new \InvalidArgumentException('--max-cases-per-minute debe ser mayor que cero.');
        }

        return 60 / (float) $perMinute;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readCases(string $path): ?array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->error("No se pudo leer el dataset: {$path}");

            return null;
        }

        $cases = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->error('JSON inválido en línea '.($lineNumber + 1).": {$exception->getMessage()}");

                return null;
            }
            if (! is_array($case) || ! isset($case['id'])) {
                $this->error('Cada línea debe ser un objeto de caso con id.');

                return null;
            }
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array<string, mixed>
     */
    private function loadManifest(string $path, string $runId, string $mode, array $flags): array
    {
        if (is_file($path)) {
            $manifest = json_decode((string) file_get_contents($path), true);
            if (is_array($manifest)) {
                return $manifest;
            }
        }

        return [
            'schema_version' => 1,
            'run_id' => $runId,
            'mode' => $mode,
            'flags' => $flags,
            'created_at' => now()->toIso8601String(),
            'algorithm_version' => config('scoring.algorithm_version'),
            'git_commit' => trim((string) @shell_exec('git rev-parse HEAD')),
            'cases' => [],
        ];
    }

    private function isCompleteSnapshot(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $snapshot = json_decode((string) file_get_contents($path), true);

        return is_array($snapshot) && ($snapshot['status'] ?? null) === 'completed';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJsonAtomically(string $path, array $data): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException("No se pudo crear el directorio {$directory}.");
        }
        $temporaryPath = $path.'.'.bin2hex(random_bytes(5)).'.tmp';
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryPath, $encoded.PHP_EOL, LOCK_EX) === false || ! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException("No se pudo escribir el archivo {$path} de forma atómica.");
        }
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            ? $path
            : base_path($path);
    }

    private function ensureEvaluationDatabaseFile(): void
    {
        if (config('database.connections.evaluation.driver') !== 'sqlite') {
            return;
        }

        $database = (string) config('database.connections.evaluation.database');
        if ($database === ':memory:' || is_file($database)) {
            return;
        }
        $directory = dirname($database);
        if (! is_dir($directory) || ! touch($database)) {
            throw new \RuntimeException("No se pudo crear el archivo SQLite de evaluación: {$database}");
        }
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).'\\';

        return str_starts_with(strtolower($path), strtolower($base))
            ? str_replace('\\', '/', substr($path, strlen($base)))
            : $path;
    }
}
