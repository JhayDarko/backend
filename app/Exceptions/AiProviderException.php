<?php

namespace App\Exceptions;

use Exception;

class AiProviderException extends Exception
{
    /**
     * Create a timeout exception.
     */
    public static function timeout(int $seconds): self
    {
        return new self("AI provider request timed out after {$seconds} seconds.");
    }

    /**
     * Create a connection exception.
     */
    public static function connection(string $message): self
    {
        return new self("Failed to connect to AI provider: {$message}");
    }

    /**
     * Create an HTTP error exception.
     */
    public static function httpError(int $status, string $message): self
    {
        return new self("AI provider HTTP error {$status}: {$message}");
    }

    /**
     * Create a rate limit exception.
     */
    public static function rateLimit(?int $retryAfter = null): self
    {
        $msg = 'AI provider rate limit exceeded';
        if ($retryAfter) {
            $msg .= " (retry after {$retryAfter}s)";
        }

        return new self($msg, 429);
    }

    public static function quotaExhausted(string $provider, string $message): self
    {
        return new self("{$provider} quota is exhausted: {$message}", 402);
    }

    /**
     * Create an invalid response exception.
     */
    public static function invalidResponse(string $message): self
    {
        return new self("Invalid response from AI provider: {$message}");
    }

    public static function responseValidation(string $message): self
    {
        return self::invalidResponse($message);
    }

    /**
     * Create a validation exception.
     */
    public static function validation(array $errors): self
    {
        $errorText = implode('; ', $errors);

        return new self("AI provider response validation failed: {$errorText}");
    }

    /**
     * Create a configuration exception.
     */
    public static function configuration(string $message): self
    {
        return new self("AI provider configuration error: {$message}");
    }
}
