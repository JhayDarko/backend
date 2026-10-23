<?php

namespace Tests\Feature\Database;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArticleDeduplicationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_consolidates_duplicate_articles_with_a_reversible_snapshot(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_scope_article_deduplication_to_users.php');
        $migration->down();

        $owner = User::factory()->create();
        $analyst = User::factory()->create();
        $first = $this->createArticle($owner, 'https://example.test/news', str_repeat('a', 64));
        $duplicate = $this->createArticle($owner, 'https://example.test/news', str_repeat('a', 64));
        $firstAnalysis = Analysis::query()->create([
            'article_id' => $first->id,
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);
        $duplicateAnalysis = Analysis::query()->create([
            'article_id' => $duplicate->id,
            'user_id' => $analyst->id,
            'status' => 'pending',
        ]);

        $migration->up();

        $survivorId = min($first->id, $duplicate->id);
        $deletedId = max($first->id, $duplicate->id);
        $this->assertSame(1, Article::query()->whereIn('id', [$first->id, $duplicate->id])->count());
        $this->assertSame($survivorId, $firstAnalysis->fresh()->article_id);
        $this->assertSame($survivorId, $duplicateAnalysis->fresh()->article_id);
        $this->assertSame(1, DB::table('article_deduplication_snapshots')->count());
        $this->assertTrue(Schema::hasIndex('articles', 'articles_created_by_canonical_url_unique'));
        $this->assertTrue(Schema::hasIndex('articles', 'articles_created_by_content_hash_unique'));

        $migration->down();

        $this->assertSame(2, Article::query()->whereIn('id', [$first->id, $duplicate->id])->count());
        $this->assertSame($first->id, $firstAnalysis->fresh()->article_id);
        $this->assertSame($deletedId, $duplicateAnalysis->fresh()->article_id);
        $this->assertFalse(Schema::hasTable('article_deduplication_snapshots'));
        $this->assertFalse(Schema::hasIndex('articles', 'articles_created_by_canonical_url_unique'));
        $this->assertFalse(Schema::hasIndex('articles', 'articles_created_by_content_hash_unique'));
    }

    public function test_it_aborts_without_mutating_articles_when_analyses_would_collide(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_scope_article_deduplication_to_users.php');
        $migration->down();

        $owner = User::factory()->create();
        $first = $this->createArticle($owner, 'https://example.test/collision', str_repeat('b', 64));
        $duplicate = $this->createArticle($owner, 'https://example.test/collision', str_repeat('b', 64));
        $firstAnalysis = Analysis::query()->create([
            'article_id' => $first->id,
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);
        $duplicateAnalysis = Analysis::query()->create([
            'article_id' => $duplicate->id,
            'user_id' => $owner->id,
            'status' => 'pending',
        ]);

        try {
            $migration->up();
            $this->fail('The migration must abort when analyses would violate per-user uniqueness.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) $firstAnalysis->id, $exception->getMessage());
            $this->assertStringContainsString((string) $duplicateAnalysis->id, $exception->getMessage());
        }

        $this->assertSame(2, Article::query()->whereIn('id', [$first->id, $duplicate->id])->count());
        $this->assertSame($first->id, $firstAnalysis->fresh()->article_id);
        $this->assertSame($duplicate->id, $duplicateAnalysis->fresh()->article_id);
        $this->assertFalse(Schema::hasTable('article_deduplication_snapshots'));
    }

    public function test_it_merges_transitive_duplicates_found_by_different_identity_keys(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_scope_article_deduplication_to_users.php');
        $migration->down();

        $owner = User::factory()->create();
        $first = $this->createArticle($owner, 'https://example.test/first', str_repeat('d', 64));
        $middle = $this->createArticle($owner, 'https://example.test/first', str_repeat('e', 64));
        $last = $this->createArticle($owner, 'https://example.test/last', str_repeat('e', 64));

        $migration->up();

        $this->assertSame(1, Article::query()->whereIn('id', [$first->id, $middle->id, $last->id])->count());
        $this->assertSame(min($first->id, $middle->id, $last->id), Article::query()->whereIn('id', [$first->id, $middle->id, $last->id])->value('id'));
        $this->assertSame(2, DB::table('article_deduplication_snapshots')->count());
    }

    public function test_unique_indexes_allow_duplicates_when_nullable_identity_columns_are_null(): void
    {
        $owner = User::factory()->create();

        $this->createArticle($owner, null, null);
        $this->createArticle($owner, null, null);
        $this->createArticle(null, 'https://example.test/unowned', str_repeat('c', 64));
        $this->createArticle(null, 'https://example.test/unowned', str_repeat('c', 64));

        $this->assertSame(4, Article::query()->count());
    }

    private function createArticle(?User $owner, ?string $canonicalUrl, ?string $contentHash): Article
    {
        return Article::query()->create([
            'created_by' => $owner?->id,
            'url' => $canonicalUrl ?? 'https://example.test/raw',
            'canonical_url' => $canonicalUrl,
            'content_hash' => $contentHash,
            'title' => 'Noticia duplicada de prueba',
            'content' => 'Contenido duplicado para comprobar la consolidación.',
        ]);
    }
}
