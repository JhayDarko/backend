<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\Analyst\AnalystProviderInterface;
use App\Contracts\ClassifierProviderInterface;
use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Contracts\Search\SearchProviderInterface;
use App\Services\AI\Providers\BertFakeNewsProvider;
use App\Services\AI\Providers\FallbackAiProvider;
use App\Services\AI\Providers\GeminiAnalystProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\FactCheck\GoogleFactCheckProvider;
use App\Services\Search\SearchProviderManager;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        // Claim verification jobs are independent; several workers process them in parallel.
        // Same name as the framework default so this replaces the single `queue:listen` process.
        DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', 'queue');
        for ($worker = 2; $worker <= max(1, (int) config('queue.dev_workers', 3)); $worker++) {
            DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', "queue-{$worker}");
        }
    }
}
