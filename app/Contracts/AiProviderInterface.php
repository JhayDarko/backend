<?php

namespace App\Contracts;

use App\Exceptions\AiProviderException;
use App\Services\AI\DTO\ExtractedClaimsResponse;

interface AiProviderInterface
{
    /**
     * Extract claims from article content using AI provider.
     *
     * @param  string  $title  Article title
     * @param  string  $content  Article content
     * @param  string|null  $description  Article description
     * @return ExtractedClaimsResponse Structured response with extracted claims
     *
     * @throws AiProviderException
     */
    public function extractClaims(
        string $title,
        string $content,
        ?string $description = null,
    ): ExtractedClaimsResponse;

    /**
     * Get provider name.
     *
     * @return string Provider identifier (e.g., 'gemini', 'openai')
     */
    public function getName(): string;

    /**
     * Get model name currently in use.
     *
     * @return string Model identifier
     */
    public function getModel(): string;
}
