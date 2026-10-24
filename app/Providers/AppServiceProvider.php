<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Contracts\Analyst\AnalystProviderInterface;
use App\Contracts\Article\HostResolverInterface;
use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Contracts\ClassifierProviderInterface;
use App\Contracts\FactCheck\FactCheckProviderInterface;
use App\Contracts\Search\SearchProviderInterface;
use App\Models\User;
use App\Services\AI\Providers\BertFakeNewsProvider;
use App\Services\AI\Providers\FallbackAiProvider;
use App\Services\AI\Providers\GeminiAnalystProvider;
use App\Services\Article\SystemHostResolver;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\FactCheck\GoogleFactCheckProvider;
use App\Services\Search\SearchProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

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
        $this->app->singleton(GoogleIdTokenVerifierInterface::class, GoogleIdTokenVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-login', fn (Request $request): array => $this->authRateLimits($request, 'login'));
        RateLimiter::for('auth-register', fn (Request $request): array => $this->authRateLimits($request, 'register'));
        RateLimiter::for('auth-refresh', fn (Request $request): array => $this->authRateLimits($request, 'refresh'));

        // Claim verification jobs are independent; several workers process them in parallel.
        // Same name as the framework default so this replaces the single `queue:listen` process.
        DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', 'queue');
        for ($worker = 2; $worker <= max(1, (int) config('queue.dev_workers', 3)); $worker++) {
            DevCommands::artisan('queue:work --sleep=1 --tries=4 --backoff=10 --timeout=0', "queue-{$worker}");
        }
        DevCommands::register('npm --prefix frontend start', 'web');
    }

    /** @return array<int, Limit> */
    private function authRateLimits(Request $request, string $action): array
    {
        $email = (string) $request->input('email', $request->user()?->email);
        if ($email === '') {
            $token = $request->bearerToken();
            $tokenable = $token === null ? null : PersonalAccessToken::findToken($token)?->tokenable;
            $email = $tokenable instanceof User ? $tokenable->email : '';
        }
        $email = mb_strtolower(trim($email));
        $onLimit = fn (Request $request, array $headers): JsonResponse => response()->json([
            'code' => 'rate_limit_exceeded',
            'message' => 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.',
        ], 429, $headers);

        return [
            Limit::perMinute(10)->by("auth-{$action}-ip:{$request->ip()}")->response($onLimit),
            Limit::perMinute(10)->by("auth-{$action}-email:".hash('sha256', $email))->response($onLimit),
        ];
    }
}
