<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\Analyst\AnalystProviderInterface;
use App\Contracts\ClassifierProviderInterface;
use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Contracts\Search\SearchProviderInterface;
use App\Services\AI\Providers\BertFakeNewsProvider;
use App\Services\AI\Providers\GeminiAnalystProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\FactCheck\GoogleFactCheckProvider;
use App\Services\Search\SearchProviderManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProviderInterface::class, GeminiProvider::class);
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
        //
    }
}
