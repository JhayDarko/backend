<?php

namespace App\Services\AI\Providers;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;
use Illuminate\Support\Facades\Log;

final class FallbackAiProvider implements AiProviderInterface
{
    private ?AiProviderInterface $activeProvider = null;

    public function __construct(
        private GeminiProvider $gemini,
        private OpenAiProvider $openai,
    ) {}

    public function extractClaims(string $title, string $content, ?string $description = null): ExtractedClaimsResponse
    {
        $primary = $this->providerByName((string) config('ai.provider', 'gemini'));
        $fallback = $this->providerByName((string) config('ai.fallback_provider', 'openai'));

        try {
            $result = $primary->extractClaims($title, $content, $description);
            $this->activeProvider = $primary;

            return $result;
        } catch (AiProviderException $exception) {
            $fallbackEnabled = (bool) config('ai.fallback_enabled', true);
            $isRateLimited = $exception->getCode() === 429
                || str_contains(strtolower($exception->getMessage()), 'rate limit');

            if (! $fallbackEnabled || ! $isRateLimited || $primary->getName() === $fallback->getName()) {
                throw $exception;
            }

            Log::warning('Primary AI provider rate-limited; using fallback provider', [
                'primary' => $primary->getName(),
                'fallback' => $fallback->getName(),
                'error' => $exception->getMessage(),
            ]);

            try {
                $result = $fallback->extractClaims($title, $content, $description);
                $this->activeProvider = $fallback;

                return $result;
            } catch (AiProviderException $fallbackException) {
                throw new AiProviderException(
                    sprintf(
                        'Primary provider %s failed: %s. Fallback provider %s also failed: %s',
                        $primary->getName(),
                        $exception->getMessage(),
                        $fallback->getName(),
                        $fallbackException->getMessage(),
                    ),
                    $fallbackException->getCode(),
                    $fallbackException,
                );
            }
        }
    }

    public function getName(): string
    {
        return ($this->activeProvider ?? $this->providerByName((string) config('ai.provider', 'gemini')))->getName();
    }

    public function getModel(): string
    {
        return ($this->activeProvider ?? $this->providerByName((string) config('ai.provider', 'gemini')))->getModel();
    }

    private function providerByName(string $name): AiProviderInterface
    {
        return match ($name) {
            'openai' => $this->openai,
            default => $this->gemini,
        };
    }
}
