<?php

use App\Exceptions\ArticleInputException;
use App\Exceptions\IngestionProviderUnavailableException;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RejectExpiredSessionToken;
use App\Http\Middleware\UnescapeJsonUnicode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(UnescapeJsonUnicode::class);
        $middleware->append(RejectExpiredSessionToken::class);
        $middleware->trustProxies(at: '*');
        $middleware->alias(['api.key' => AuthenticateApiKey::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $isIngestionRequest = static function (Request $request): bool {
            return $request->isMethod('POST') && in_array($request->path(), [
                'api/v1/articles',
                'api/v1/articles/ocr',
                'api/public/v1/analyses',
            ], true);
        };

        $exceptions->report(function (Throwable $exception) use ($isIngestionRequest): ?bool {
            $request = request();

            if (! $isIngestionRequest($request)
                || $exception instanceof ValidationException
                || $exception instanceof ArticleInputException
                || $exception instanceof IngestionProviderUnavailableException
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ModelNotFoundException
                || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500)) {
                return null;
            }

            Log::error('Unexpected ingestion failure.', [
                'exception' => $exception,
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return false;
        });

        $exceptions->render(function (Throwable $exception, Request $request) use ($isIngestionRequest) {
            if (! $isIngestionRequest($request)) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                $errors = $exception->errors();
                if (in_array(ArticleInputException::BLOCKED_URL_VALIDATION_MESSAGE, $errors['url'] ?? [], true)) {
                    return response()->json([
                        'code' => 'url_blocked',
                        'message' => ArticleInputException::BLOCKED_URL_MESSAGE,
                    ], 422);
                }

                return response()->json([
                    'code' => 'invalid_input',
                    'message' => 'Los datos enviados no son válidos.',
                    'errors' => $errors,
                ], 422);
            }

            if ($exception instanceof ArticleInputException) {
                return response()->json([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            if ($exception instanceof IngestionProviderUnavailableException) {
                return response()->json([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ], 503);
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json([
                    'code' => 'unauthenticated',
                    'message' => 'Debes iniciar sesión para realizar esta solicitud.',
                ], 401);
            }

            if ($exception instanceof AuthorizationException) {
                return response()->json([
                    'code' => 'forbidden',
                    'message' => 'No tienes permiso para realizar esta solicitud.',
                ], $exception->status() ?? 403);
            }

            if ($exception instanceof ModelNotFoundException) {
                return response()->json([
                    'code' => 'not_found',
                    'message' => 'No se encontró el recurso solicitado.',
                ], 404);
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();

                if ($status >= 500) {
                    return response()->json([
                        'code' => 'internal_error',
                        'message' => 'Ocurrió un error interno. Inténtalo de nuevo más tarde.',
                    ], 500, $exception->getHeaders());
                }

                [$code, $message] = match ($status) {
                    401 => ['unauthenticated', 'Debes iniciar sesión para realizar esta solicitud.'],
                    403 => ['forbidden', 'No tienes permiso para realizar esta solicitud.'],
                    404 => ['not_found', 'No se encontró el recurso solicitado.'],
                    413 => ['payload_too_large', 'La solicitud supera el tamaño máximo permitido.'],
                    429 => ['rate_limit_exceeded', 'Se alcanzó el límite de solicitudes. Inténtalo más tarde.'],
                    default => ['request_error', 'No se pudo procesar la solicitud.'],
                };

                return response()->json([
                    'code' => $code,
                    'message' => $message,
                ], $status, $exception->getHeaders());
            }

            return response()->json([
                'code' => 'internal_error',
                'message' => 'Ocurrió un error interno. Inténtalo de nuevo más tarde.',
            ], 500);
        });
    })->create();
