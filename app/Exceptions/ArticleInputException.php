<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ArticleInputException extends RuntimeException
{
    public const BLOCKED_URL_VALIDATION_MESSAGE = 'La URL no está permitida.';

    public const BLOCKED_URL_MESSAGE = 'No se permite acceder a esa dirección.';

    private function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function blockedUrl(?Throwable $previous = null): self
    {
        return new self('url_blocked', self::BLOCKED_URL_MESSAGE, $previous);
    }

    public static function fetchFailed(?Throwable $previous = null): self
    {
        return new self(
            'fetch_failed',
            'No se pudo descargar la noticia. Verifica el enlace e inténtalo de nuevo.',
            $previous,
        );
    }

    public static function contentNotExtractable(?Throwable $previous = null): self
    {
        return new self(
            'content_not_extractable',
            'La página no contiene texto de noticia que se pueda extraer.',
            $previous,
        );
    }
}
