<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\ApiUsage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates public API calls with an API key and applies the plan's per-minute limit. */
final class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->header('X-API-Key') ?: $request->bearerToken();
        $key = $plain
            ? ApiKey::query()->with('user')->where('key_hash', ApiKey::hash($plain))->whereNull('revoked_at')->first()
            : null;

        if (! $key) {
            return response()->json([
                'code' => 'invalid_api_key',
                'message' => 'API key inválida o ausente.',
            ], 401);
        }

        $perMinute = (int) $key->limits()['per_minute'];
        $limiterKey = 'api-key:'.$key->id;

        if (RateLimiter::tooManyAttempts($limiterKey, $perMinute)) {
            return response()->json([
                'code' => 'rate_limit_exceeded',
                'message' => 'Límite de solicitudes por minuto excedido para tu plan.',
            ], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($limiterKey))
                ->header('X-RateLimit-Limit', (string) $perMinute)
                ->header('X-RateLimit-Remaining', '0');
        }

        RateLimiter::hit($limiterKey, 60);

        Auth::setUser($key->user);
        $request->setUserResolver(fn () => $key->user);
        $request->attributes->set('api_key', $key);

        $response = $next($request);

        $usage = ApiUsage::query()->firstOrCreate(['api_key_id' => $key->id, 'date' => today()->toDateString()]);
        $usage->increment('requests');
        if ($response->getStatusCode() >= 400) {
            $usage->increment('errors');
        }
        $key->forceFill(['last_used_at' => now()])->save();

        $response->headers->set('X-RateLimit-Limit', (string) $perMinute);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($limiterKey, $perMinute));

        return $response;
    }
}
