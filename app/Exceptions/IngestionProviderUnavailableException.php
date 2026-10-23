<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class IngestionProviderUnavailableException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function ocr(?Throwable $previous = null): self
    {
        return new self(
            'ocr_unavailable',
            'El servicio de reconocimiento de imágenes no está disponible. Inténtalo de nuevo.',
            $previous,
        );
    }

    public static function gemini(?Throwable $previous = null): self
    {
        return new self(
            'gemini_unavailable',
            'El servicio de clasificación de texto no está disponible. Inténtalo de nuevo.',
            $previous,
        );
    }
}
