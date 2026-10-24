<?php

namespace App\Services\Auth;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Exceptions\GoogleIdentityException;
use App\Exceptions\GoogleVerificationUnavailableException;

final class GoogleIdentityClaimsService
{
    public function __construct(private readonly GoogleIdTokenVerifierInterface $verifier) {}

    /** @return array{sub: string, email: string, name: string, issued_at: ?int} */
    public function verify(string $credential): array
    {
        $clientId = trim((string) config('services.google.client_id'));
        if ($clientId === '') {
            throw new GoogleIdentityException(
                503,
                'google_auth_unavailable',
                'El inicio de sesión con Google no está configurado.',
            );
        }

        try {
            $claims = $this->verifier->verify($credential);
        } catch (GoogleVerificationUnavailableException $exception) {
            throw new GoogleIdentityException(
                503,
                'google_auth_unavailable',
                'No se pudo verificar Google en este momento. Inténtalo de nuevo más tarde.',
                $exception,
            );
        }

        if ($claims === null
            || ($claims['iss'] ?? null) !== 'https://accounts.google.com'
            || ($claims['aud'] ?? null) !== $clientId
            || ! is_numeric($claims['exp'] ?? null)
            || (int) $claims['exp'] <= now()->timestamp) {
            throw new GoogleIdentityException(
                401,
                'google_token_invalid',
                'La credencial de Google no es válida o ha expirado.',
            );
        }

        if (($claims['email_verified'] ?? false) !== true) {
            throw new GoogleIdentityException(
                401,
                'google_email_not_verified',
                'Google debe confirmar el correo electrónico antes de iniciar sesión.',
            );
        }

        $subject = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (! is_string($subject) || $subject === '' || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new GoogleIdentityException(
                401,
                'google_token_invalid',
                'La credencial de Google no contiene una identidad válida.',
            );
        }

        $name = $claims['name'] ?? null;

        return [
            'sub' => $subject,
            'email' => mb_strtolower($email),
            'name' => is_string($name) && trim($name) !== '' ? trim($name) : $email,
            'issued_at' => is_numeric($claims['iat'] ?? null) ? (int) $claims['iat'] : null,
        ];
    }
}
