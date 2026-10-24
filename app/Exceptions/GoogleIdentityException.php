<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class GoogleIdentityException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
