<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RejectExpiredSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            ! $request->is('api/v1/*')
            || $request->is('api/v1/auth/login', 'api/v1/auth/register')
        ) {
            return $next($request);
        }

        $plainTextToken = $request->bearerToken();

        if ($plainTextToken !== null) {
            $token = PersonalAccessToken::findToken($plainTextToken);

            if ($token !== null) {
                $expiration = $token->expires_at;
                $expirationMinutes = config('sanctum.expiration');

                if ($expiration === null && $expirationMinutes !== null) {
                    $expiration = $token->created_at?->copy()->addMinutes((int) $expirationMinutes);
                }

                if ($expiration !== null && $expiration->isPast()) {
                    return response()->json([
                        'code' => 'token_expired',
                        'message' => 'Tu sesión expiró. Inicia sesión de nuevo.',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
