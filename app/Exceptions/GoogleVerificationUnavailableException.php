<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GoogleVerificationUnavailableException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Google identity verification is unavailable.', previous: $previous);
    }
}
