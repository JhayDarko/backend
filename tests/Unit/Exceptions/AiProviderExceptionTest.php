<?php

namespace Tests\Unit\Exceptions;

use App\Exceptions\AiProviderException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiProviderExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_timeout_exception(): void
    {
        $exception = AiProviderException::timeout(60);

        $this->assertSame('AI provider request timed out after 60 seconds.', $exception->getMessage());
    }

    #[Test]
    public function it_creates_connection_exception(): void
    {
        $exception = AiProviderException::connection('Network unreachable');

        $this->assertStringContainsString('Failed to connect', $exception->getMessage());
        $this->assertStringContainsString('Network unreachable', $exception->getMessage());
    }

    #[Test]
    public function it_creates_http_error_exception(): void
    {
        $exception = AiProviderException::httpError(500, 'Internal Server Error');

        $this->assertStringContainsString('500', $exception->getMessage());
        $this->assertStringContainsString('Internal Server Error', $exception->getMessage());
    }

    #[Test]
    public function it_creates_rate_limit_exception_without_retry_after(): void
    {
        $exception = AiProviderException::rateLimit();

        $this->assertStringContainsString('rate limit exceeded', $exception->getMessage());
    }

    #[Test]
    public function it_creates_rate_limit_exception_with_retry_after(): void
    {
        $exception = AiProviderException::rateLimit(120);

        $this->assertStringContainsString('rate limit exceeded', $exception->getMessage());
        $this->assertStringContainsString('120', $exception->getMessage());
    }

    #[Test]
    public function it_creates_invalid_response_exception(): void
    {
        $exception = AiProviderException::invalidResponse('Missing required field');

        $this->assertStringContainsString('Invalid response', $exception->getMessage());
        $this->assertStringContainsString('Missing required field', $exception->getMessage());
    }

    #[Test]
    public function it_creates_validation_exception(): void
    {
        $errors = ['Invalid importance', 'Missing context', 'Unknown type'];
        $exception = AiProviderException::validation($errors);

        $this->assertStringContainsString('validation failed', $exception->getMessage());
        $this->assertStringContainsString('Invalid importance', $exception->getMessage());
        $this->assertStringContainsString('Missing context', $exception->getMessage());
        $this->assertStringContainsString('Unknown type', $exception->getMessage());
    }

    #[Test]
    public function it_creates_configuration_exception(): void
    {
        $exception = AiProviderException::configuration('API key is missing');

        $this->assertStringContainsString('configuration error', $exception->getMessage());
        $this->assertStringContainsString('API key is missing', $exception->getMessage());
    }

    #[Test]
    public function it_is_throwable(): void
    {
        $exception = AiProviderException::timeout(30);

        $this->assertInstanceOf(\Throwable::class, $exception);
        $this->assertInstanceOf(\Exception::class, $exception);
    }
}
