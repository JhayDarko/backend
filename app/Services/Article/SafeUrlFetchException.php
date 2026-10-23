<?php

namespace App\Services\Article;

use RuntimeException;
use Throwable;

final class SafeUrlFetchException extends RuntimeException
{
    public const BLOCKED = 'url_blocked';

    public const FETCH_FAILED = 'fetch_failed';

    public const CONTENT_TYPE = 'unsupported_content_type';

    public const RESPONSE_TOO_LARGE = 'response_too_large';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
