<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GoogleIdentityException;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\GoogleIdentityClaimsService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::create($data);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || $user->password === null || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }

        return $this->tokenResponse($user);
    }

    public function google(Request $request, GoogleIdentityClaimsService $googleIdentity): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:8192'],
        ]);
        $claims = $this->googleClaimsOrResponse($data['credential'], $googleIdentity);
        if ($claims instanceof JsonResponse) {
            return $claims;
        }

        $linked = SocialAccount::query()
            ->where('provider', 'google')
            ->where('provider_user_id', $claims['sub'])
            ->first();

        if ($linked !== null) {
            return $this->tokenResponse($linked->user);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$claims['email']])
            ->first();

        if ($user !== null) {
            return $this->googleError(
                409,
                'account_exists',
                'Ya existe una cuenta con este correo. Inicia sesión con contraseña y vincula Google desde Preferencias.',
            );
        }

        try {
            $user = DB::transaction(function () use ($claims): User {
                $user = User::create([
                    'name' => $claims['name'],
                    'email' => $claims['email'],
                    'password' => null,
                ]);
                $user->forceFill(['email_verified_at' => now()])->save();

                $user->socialAccounts()->create([
                    'provider' => 'google',
                    'provider_user_id' => $claims['sub'],
                    'email' => $claims['email'],
                ]);

                return $user;
            });
        } catch (QueryException $exception) {
            $linkedUser = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $claims['sub'])
                ->first()?->user;

            if ($linkedUser !== null) {
                return $this->tokenResponse($linkedUser);
            }

            if (User::query()->whereRaw('LOWER(email) = ?', [$claims['email']])->exists()) {
                return $this->googleError(
                    409,
                    'account_exists',
                    'Ya existe una cuenta con este correo. Inicia sesión con contraseña y vincula Google desde Preferencias.',
                );
            }

            throw $exception;
        }

        return $this->tokenResponse($user, 201);
    }

    public function linkGoogle(Request $request, GoogleIdentityClaimsService $googleIdentity): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string', 'max:8192'],
        ]);
        $claims = $this->googleClaimsOrResponse($data['credential'], $googleIdentity);
        if ($claims instanceof JsonResponse) {
            return $claims;
        }

        $user = $request->user();
        $account = SocialAccount::query()
            ->where('provider', 'google')
            ->where('provider_user_id', $claims['sub'])
            ->first();

        if ($account !== null && $account->user_id !== $user->id) {
            return $this->googleError(
                409,
                'google_account_already_linked',
                'Esta cuenta de Google ya está vinculada a otra cuenta de FactNode.',
            );
        }

        try {
            DB::transaction(function () use ($user, $claims): void {
                $currentAccount = $user->socialAccounts()
                    ->where('provider', 'google')
                    ->lockForUpdate()
                    ->first();

                if ($currentAccount?->provider_user_id === $claims['sub']) {
                    $currentAccount->update(['email' => $claims['email']]);

                    return;
                }

                $currentAccount?->delete();
                $user->socialAccounts()->create([
                    'provider' => 'google',
                    'provider_user_id' => $claims['sub'],
                    'email' => $claims['email'],
                ]);
            });
        } catch (QueryException $exception) {
            $linkedAccount = SocialAccount::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $claims['sub'])
                ->first();

            if ($linkedAccount !== null && $linkedAccount->user_id !== $user->id) {
                return $this->googleError(
                    409,
                    'google_account_already_linked',
                    'Esta cuenta de Google ya está vinculada a otra cuenta de FactNode.',
                );
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Cuenta de Google vinculada.',
            'data' => $this->userPayload($user->fresh()),
        ]);
    }

    public function unlinkGoogle(Request $request): JsonResponse
    {
        $user = $request->user();
        $hasAlternativeSignIn = $user->password !== null
            || $user->socialAccounts()->where('provider', '!=', 'google')->exists();

        if (! $hasAlternativeSignIn) {
            return $this->googleError(
                409,
                'last_auth_method',
                'No puedes desvincular Google porque es tu único método de acceso.',
            );
        }

        $user->socialAccounts()->where('provider', 'google')->delete();

        return response()->json([
            'message' => 'Cuenta de Google desvinculada.',
            'data' => $this->userPayload($user->fresh()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $currentToken = $this->persistentToken($request->user()->currentAccessToken());
        if ($currentToken !== null) {
            $currentToken->delete();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Todas las sesiones se cerraron.']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $this->persistentToken($user->currentAccessToken());

        if ($currentToken === null) {
            return response()->json([
                'code' => 'unauthenticated',
                'message' => 'Debes iniciar sesión para realizar esta solicitud.',
            ], 401);
        }

        return DB::transaction(function () use ($user, $currentToken): JsonResponse {
            $response = $this->tokenResponse($user);
            $currentToken->delete();

            return $response;
        });
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        $currentToken = $this->persistentToken($user->currentAccessToken());

        DB::transaction(function () use ($user, $currentToken, $data): void {
            $user->password = $data['password'];
            $user->save();

            if ($currentToken !== null) {
                $user->tokens()->where('id', '<>', $currentToken->getKey())->delete();
            } else {
                $user->tokens()->delete();
            }
        });

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addMinutes($this->tokenTtlMinutes());

        return response()->json([
            'data' => $this->userPayload($user),
            'token' => $user->createToken('web', ['*'], $expiresAt)->plainTextToken,
            'expires_at' => $expiresAt->toISOString(),
        ], $status);
    }

    /**
     * @return array{sub: string, email: string, name: string, issued_at: ?int}|JsonResponse
     */
    private function googleClaimsOrResponse(
        string $credential,
        GoogleIdentityClaimsService $googleIdentity,
    ): array|JsonResponse {
        try {
            return $googleIdentity->verify($credential);
        } catch (GoogleIdentityException $exception) {
            return $this->googleError(
                $exception->httpStatus,
                $exception->errorCode,
                $exception->getMessage(),
            );
        }
    }

    private function googleError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status);
    }

    private function tokenTtlMinutes(): int
    {
        return max(1, (int) config('sanctum.expiration', 10080));
    }

    private function persistentToken(mixed $token): ?PersonalAccessToken
    {
        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'auth_methods' => [
                ...($user->password !== null ? ['password'] : []),
                ...($user->socialAccounts()->exists() ? ['google'] : []),
            ],
        ];
    }
}
