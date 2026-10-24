<?php

namespace App\Contracts\Auth;

interface GoogleIdTokenVerifierInterface
{
    /** @return array<string, mixed>|null */
    public function verify(string $idToken): ?array;
}
