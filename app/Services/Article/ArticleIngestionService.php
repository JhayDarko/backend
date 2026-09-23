<?php

namespace App\Services\Article;

use App\Models\Article;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

final class ArticleIngestionService
{
    public function __construct(
        private readonly ArticleExtractionService $extractor,
        private readonly DomainNormalizer $domainNormalizer,
    ) {}

    public function ingest(string $url): Article
    {
        $data = $this->extractor->extract($url);

        return DB::transaction(function () use ($data) {
            $domain = $this->domainNormalizer->normalize($data->url);

            $source = Source::firstOrCreate(
                [
                    'domain' => $domain,
                ],
                [
                    'name' => $domain,
                    'url' => 'https://' . $domain,
                ]
            );

            $contentHash = $data->content
                ? hash('sha256', $data->content)
                : null;

            $existingArticle = Article::query()
                ->when(
                    $data->canonicalUrl,
                    fn ($query) => $query->where(
                        'canonical_url',
                        $data->canonicalUrl
                    )
                )
                ->when(
                    $contentHash,
                    fn ($query) => $query->orWhere(
                        'content_hash',
                        $contentHash
                    )
                )
                ->first();

            if ($existingArticle) {
                return $existingArticle;
            }

            return Article::create([
                'source_id' => $source->id,
                'url' => $data->url,
                'canonical_url' => $data->canonicalUrl,
                'title' => $data->title,
                'description' => $data->description,
                'content' => $data->content,
                'author_name' => $data->authorName,
                'published_at' => $data->publishedAt,
                'language' => $data->language,
                'content_hash' => $contentHash,
                'metadata' => $data->metadata,
            ]);
        });
    }

    public function ingestText(string $content, ?string $title = null, ?string $authorName = null): Article
    {
        $content = trim($content);
        $contentHash = hash('sha256', $content);

        $existingArticle = Article::query()
            ->where('content_hash', $contentHash)
            ->first();

        if ($existingArticle) {
            return $existingArticle;
        }

        $derivedTitle = $title ? trim($title) : mb_substr($content, 0, 80) . (mb_strlen($content) > 80 ? '...' : '');

        return Article::create([
            'source_id' => null,
            'url' => null,
            'canonical_url' => null,
            'title' => $derivedTitle,
            'description' => null,
            'content' => $content,
            'author_name' => $authorName ? trim($authorName) : null,
            'published_at' => now(),
            'language' => 'es',
            'content_hash' => $contentHash,
            'metadata' => [
                'submission_type' => 'raw_text',
            ],
        ]);
    }
}