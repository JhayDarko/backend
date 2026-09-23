<?php

namespace App\Contracts\Search;

interface SearchProviderInterface
{
    /**
     * Search for external evidence related to a query.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, array $options = []): array;

    public function getName(): string;
}
