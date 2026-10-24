<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }

        return $this->tokenResponse($user);
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
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
