<?php

namespace App\Services\Article;

use App\Models\Article;
use App\Models\Source;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ArticleIngestionService
{
    public function __construct(
        private readonly ArticleExtractionService $extractor,
        private readonly DomainNormalizer $domainNormalizer,
        private readonly ArticleRepository $articles,
    ) {}

    public function ingest(string $url, ?int $userId = null): Article
    {
        $data = $this->extractor->extract($url);

        $contentHash = $data->content
            ? hash('sha256', $data->content)
            : null;

        try {
            return DB::transaction(function () use ($data, $userId, $contentHash) {
                $existingArticle = $this->articles->findDuplicateForUser(
                    $userId,
                    $data->canonicalUrl,
                    $contentHash,
                );

                if ($existingArticle) {
                    return $existingArticle;
                }

                $domain = $this->domainNormalizer->normalize($data->url);

                $source = Source::firstOrCreate(
                    [
                        'domain' => $domain,
                    ],
                    [
                        'name' => $domain,
                        'url' => 'https://'.$domain,
                    ]
                );

                return $this->articles->create([
                    'source_id' => $source->id,
                    'created_by' => $userId,
                    'url' => $data->url,
                    'canonical_url' => $data->canonicalUrl,
                    'title' => $data->title,
                    'title_source' => 'extracted',
                    'description' => $data->description,
                    'content' => $data->content,
                    'author_name' => $data->authorName,
                    'published_at' => $data->publishedAt,
                    'language' => $data->language,
                    'content_hash' => $contentHash,
                    'metadata' => $data->metadata,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->articles->findDuplicateForUser(
                $userId,
                $data->canonicalUrl,
                $contentHash,
            ) ?? throw $exception;
        }
    }

    public function ingestText(string $content, ?string $title = null, ?string $authorName = null, ?int $userId = null, array $metadata = []): Article
    {
        $content = trim($content);
        $contentHash = hash('sha256', $content);

        $submissionType = $metadata['submission_type'] ?? 'raw_text';
        $cleanTitle = is_string($title) ? trim($title) : '';
        $titleSource = $cleanTitle === ''
            ? 'fallback'
            : ($submissionType === 'image_ocr' ? 'extracted' : 'user');
        $derivedTitle = $cleanTitle !== ''
            ? $cleanTitle
            : $this->fallbackTitle($submissionType);

        try {
            return DB::transaction(function () use ($userId, $contentHash, $derivedTitle, $titleSource, $content, $authorName, $metadata) {
                $existingArticle = $this->articles->findDuplicateForUser(
                    $userId,
                    null,
                    $contentHash,
                );

                if ($existingArticle) {
                    return $existingArticle;
                }

                return $this->articles->create([
                    'source_id' => null,
                    'created_by' => $userId,
                    'url' => null,
                    'canonical_url' => null,
                    'title' => $derivedTitle,
                    'title_source' => $titleSource,
                    'description' => null,
                    'content' => $content,
                    'author_name' => $authorName ? trim($authorName) : null,
                    'published_at' => now(),
                    'language' => 'es',
                    'content_hash' => $contentHash,
                    'metadata' => $metadata + ['submission_type' => 'raw_text'],
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->articles->findDuplicateForUser(
                $userId,
                null,
                $contentHash,
            ) ?? throw $exception;
        }
    }

    private function fallbackTitle(mixed $submissionType): string
    {
        $prefix = $submissionType === 'image_ocr' ? 'Imagen analizada' : 'Texto pegado';

        return $prefix.' · '.now()->format('d/m/Y');
    }
}
