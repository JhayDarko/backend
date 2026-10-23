<?php

namespace Tests\Unit\Services\Article;

use App\Models\Article;
use App\Models\User;
use App\Services\Article\ArticleExtractionService;
use App\Services\Article\ArticleIngestionService;
use App\Services\Article\ArticleRepository;
use App\Services\Article\DomainNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_users_existing_article_after_a_unique_constraint_race(): void
    {
        $user = User::factory()->create();
        $content = 'Texto de la noticia que genera una huella estable para esta prueba.';
        $contentHash = hash('sha256', $content);
        $existingArticle = new Article(['created_by' => $user->id, 'content_hash' => $contentHash]);
        $existingArticle->setAttribute('id', 42);
        $uniqueViolation = new UniqueConstraintViolationException(
            'sqlite',
            'insert into articles',
            [],
            new \PDOException('UNIQUE constraint failed', 23000),
        );

        $repository = \Mockery::mock(ArticleRepository::class);
        $repository->shouldReceive('findDuplicateForUser')
            ->twice()
            ->with($user->id, null, $contentHash)
            ->andReturn(null, $existingArticle);
        $repository->shouldReceive('create')->once()->andThrow($uniqueViolation);

        $service = new ArticleIngestionService(
            app(ArticleExtractionService::class),
            new DomainNormalizer,
            $repository,
        );

        $result = $service->ingestText($content, userId: $user->id);

        $this->assertSame($existingArticle, $result);
    }
}
