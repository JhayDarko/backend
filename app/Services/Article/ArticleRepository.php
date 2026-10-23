<?php

namespace App\Services\Article;

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;

class ArticleRepository
{
    public function findDuplicateForUser(
        ?int $userId,
        ?string $canonicalUrl,
        ?string $contentHash,
    ): ?Article {
        if ($userId === null || ($canonicalUrl === null && $contentHash === null)) {
            return null;
        }

        return Article::query()
            ->where('created_by', $userId)
            ->where(function (Builder $query) use ($canonicalUrl, $contentHash): void {
                if ($canonicalUrl !== null) {
                    $query->where('canonical_url', $canonicalUrl);
                }

                if ($contentHash !== null) {
                    $method = $canonicalUrl === null ? 'where' : 'orWhere';
                    $query->{$method}('content_hash', $contentHash);
                }
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Article
    {
        return Article::query()->create($attributes);
    }
}
