<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\Analyst\AnalystProviderInterface;
use App\Contracts\Article\HostResolverInterface;
use App\Contracts\ClassifierProviderInterface;
use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Contracts\Search\SearchProviderInterface;
use App\Services\AI\Providers\BertFakeNewsProvider;
use App\Services\AI\Providers\FallbackAiProvider;
use App\Services\AI\Providers\GeminiAnalystProvider;
use App\Services\Article\SystemHostResolver;
use App\Services\Evaluation\EvaluationRuntime;
use App\Services\Evaluation\EvaluationSnapshotSanitizer;
use App\Services\Evaluation\FakeEvaluationAiProvider;
use App\Services\Evaluation\FakeEvaluationAnalystProvider;
use App\Services\Evaluation\FakeEvaluationClassifier;
use App\Services\Evaluation\FakeEvaluationFactCheckProvider;
use App\Services\Evaluation\FakeEvaluationSearchProvider;
use App\Services\FactCheck\GoogleFactCheckProvider;
use App\Services\Search\SearchProviderManager;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostResolverInterface::class, SystemHostResolver::class);
        $this->app->bind(AiProviderInterface::class, FallbackAiProvider::class);
        $this->app->bind(AnalystProviderInterface::class, GeminiAnalystProvider::class);
        $this->app->bind(ClassifierProviderInterface::class, BertFakeNewsProvider::class);
        $this->app->bind(FactCheckProviderInterface::class, GoogleFactCheckProvider::class);
        $this->app->singleton(SearchProviderInterface::class, SearchProviderManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (getenv('EVAL_PROCESS') === '1') {
            $readEnvironment = static function (string $key): ?string {
                $value = getenv($key);

                return $value === false ? null : $value;
            };
            $excludedDomains = json_decode($readEnvironment('EVAL_EXCLUDED_DOMAINS') ?? '[]', true);
            config([
                'evaluation.process' => true,
                'evaluation.mode' => $readEnvironment('EVAL_MODE') ?? 'production',
                'evaluation.run_id' => $readEnvironment('EVAL_RUN_ID'),
                'evaluation.no_factcheck' => filter_var($readEnvironment('EVAL_NO_FACTCHECK') ?? false, FILTER_VALIDATE_BOOL),
                'evaluation.excluded_domains' => is_array($excludedDomains) ? $excludedDomains : [],
                'evaluation.case_id' => $readEnvironment('EVAL_CASE_ID'),
                'evaluation.fake_providers' => $readEnvironment('EVAL_FAKE_PROVIDERS'),
                'evaluation.test_fast_backoff' => $readEnvironment('EVAL_TEST_FAST_BACKOFF'),
                'evaluation.database.connection' => $readEnvironment('EVAL_DB_CONNECTION'),
                'evaluation.database.host' => $readEnvironment('EVAL_DB_HOST'),
                'evaluation.database.port' => $readEnvironment('EVAL_DB_PORT'),
                'evaluation.database.database' => $readEnvironment('EVAL_DB_DATABASE'),
                'evaluation.database.username' => $readEnvironment('EVAL_DB_USERNAME'),
                'evaluation.database.password' => $readEnvironment('EVAL_DB_PASSWORD'),
            ]);
            app(EvaluationRuntime::class)->activate();
            config(['cache.stores.evaluation.prefix' => 'eval:'.config('evaluation.run_id').':'.config('evaluation.mode').':']);
            $evaluationLogPath = trim((string) ($readEnvironment('EVAL_LOG_PATH') ?? ''));
            config(['logging.default' => 'evaluation']);
            if ($evaluationLogPath !== '') {
                config([
                    'logging.channels.evaluation.path' => $evaluationLogPath,
                ]);
            }
            config(['evaluation.active' => true]);

            if (app()->environment('testing') && filter_var(config('evaluation.fake_providers'), FILTER_VALIDATE_BOOL)) {
                $this->app->bind(AiProviderInterface::class, FakeEvaluationAiProvider::class);
                $this->app->bind(AnalystProviderInterface::class, FakeEvaluationAnalystProvider::class);
                $this->app->bind(ClassifierProviderInterface::class, FakeEvaluationClassifier::class);
                $this->app->bind(FactCheckProviderInterface::class, FakeEvaluationFactCheckProvider::class);
                $this->app->bind(SearchProviderInterface::class, FakeEvaluationSearchProvider::class);
            }

            Event::listen(JobProcessing::class, function (JobProcessing $event): void {
                $caseId = (string) config('evaluation.case_id', 'unknown');
                $jobName = class_basename($event->job->resolveName());
                $key = "job-attempts:{$caseId}:{$jobName}";
                $cache = Cache::store('evaluation');
                if (! $cache->has($key)) {
                    $cache->forever($key, 0);
                }
                $cache->increment($key);
            });

            Event::listen(JobFailed::class, function (JobFailed $event): void {
                $sanitizedMessage = app(EvaluationSnapshotSanitizer::class)
                    ->sanitize(['message' => $event->exception->getMessage()])['message'];
                $event->exception = new \RuntimeException(
                    is_string($sanitizedMessage) ? $sanitizedMessage : 'Evaluation job failed.',
                    (int) $event->exception->getCode(),
                );

                $caseId = (string) config('evaluation.case_id', 'unknown');
                $jobName = class_basename($event->job->resolveName());
                $failed = Cache::store('evaluation')->get("jobs-exhausted:{$caseId}", []);
                $failed[] = $jobName;
                Cache::store('evaluation')->forever("jobs-exhausted:{$caseId}", array_values(array_unique($failed)));
            });

        }

        // Claim verification jobs are independent; several workers process them in parallel.
        // Same name as the framework default so this replaces the single `queue:listen` process.
        DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', 'queue');
        for ($worker = 2; $worker <= max(1, (int) config('queue.dev_workers', 3)); $worker++) {
            DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', "queue-{$worker}");
        }
        DevCommands::register('npm --prefix frontend start', 'web');
    }
}
