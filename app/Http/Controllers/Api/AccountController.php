<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\GoogleIdentityException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Auth\GoogleIdentityClaimsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class AccountController extends Controller
{
    public function destroy(
        Request $request,
        GoogleIdentityClaimsService $googleIdentity,
        AccountDeletionService $deletion,
    ): JsonResponse {
        $validator = Validator::make($request->all(), [
            'password' => ['nullable', 'string'],
            'credential' => ['nullable', 'string', 'max:8192'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 'invalid_reauthentication',
                'message' => 'Envía una contraseña actual o una credencial reciente de Google.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $credentials = $validator->validated();
        $password = $credentials['password'] ?? null;
        $credential = $credentials['credential'] ?? null;
        $hasPassword = is_string($password) && $password !== '';
        $hasGoogleCredential = is_string($credential) && $credential !== '';

        if ($hasPassword === $hasGoogleCredential) {
            return response()->json([
                'code' => 'invalid_reauthentication',
                'message' => 'Envía una sola forma de reautenticación: contraseña o credencial de Google.',
            ], 422);
        }

        /** @var User $user */
        $user = $request->user();

        if ($hasPassword) {
            if ($user->password === null || ! Hash::check($password, $user->password)) {
                return response()->json([
                    'code' => 'reauthentication_failed',
                    'message' => 'La contraseña actual no es correcta.',
                ], 403);
            }
        } else {
            try {
                $claims = $googleIdentity->verify($credential);
            } catch (GoogleIdentityException $exception) {
                return response()->json([
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ], $exception->httpStatus);
            }

            $issuedAt = $claims['issued_at'];
            $ageSeconds = $issuedAt === null ? null : now()->timestamp - $issuedAt;
            $freshnessWindow = max(1, (int) config('auth.google_reauthentication_minutes', 5)) * 60;
            if ($ageSeconds === null || $ageSeconds < 0 || $ageSeconds > $freshnessWindow) {
                return response()->json([
                    'code' => 'google_reauthentication_expired',
                    'message' => 'Vuelve a autenticarte con Google y repite la solicitud dentro de 5 minutos.',
                ], 403);
            }

            $isLinked = $user->socialAccounts()
                ->where('provider', 'google')
                ->where('provider_user_id', $claims['sub'])
                ->exists();
            if (! $isLinked) {
                return response()->json([
                    'code' => 'google_reauthentication_failed',
                    'message' => 'La cuenta de Google no está vinculada a este usuario.',
                ], 403);
            }
        }

        $deletion($user);

        return response()->json(['message' => 'La cuenta y los datos asociados se eliminaron.']);
    }
}
