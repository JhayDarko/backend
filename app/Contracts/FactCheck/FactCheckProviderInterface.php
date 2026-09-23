<?php

namespace App\Contracts\FactCheck;

interface FactCheckProviderInterface
{
    /**
     * Search for external fact-checking results related to a claim.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array;

    public function getName(): string;
}
