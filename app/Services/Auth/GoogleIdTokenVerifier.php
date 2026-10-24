<?php

namespace App\Services\Auth;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Exceptions\GoogleVerificationUnavailableException;
use Google\Auth\AccessToken;
use RuntimeException;

final class GoogleIdTokenVerifier implements GoogleIdTokenVerifierInterface
{
    private readonly AccessToken $accessToken;

    public function __construct()
    {
        $this->accessToken = new AccessToken;
    }

    public function verify(string $idToken): ?array
    {
        try {
            $claims = $this->accessToken->verify($idToken, [
                'audience' => config('services.google.client_id'),
                'issuer' => AccessToken::OAUTH2_ISSUER_HTTPS,
            ]);
        } catch (RuntimeException $exception) {
            throw new GoogleVerificationUnavailableException($exception);
        }

        return is_array($claims) ? $claims : null;
    }
}
