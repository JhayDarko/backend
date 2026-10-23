<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SNAPSHOT_TABLE = 'article_deduplication_snapshots';

    private const CANONICAL_URL_INDEX = 'articles_created_by_canonical_url_unique';

    private const CONTENT_HASH_INDEX = 'articles_created_by_content_hash_unique';

    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create(self::SNAPSHOT_TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('article_id')->primary();
                $table->json('snapshot');
            });

            $components = $this->duplicateComponents();
            $this->assertNoAnalysisCollisions($components);

            foreach ($components as $component) {
                sort($component);
                $survivorId = array_shift($component);

                foreach ($component as $duplicateId) {
                    $article = DB::table('articles')->where('id', $duplicateId)->first();
                    $analysisIds = DB::table('analyses')
                        ->where('article_id', $duplicateId)
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(fn ($id): int => (int) $id)
                        ->all();

                    DB::table(self::SNAPSHOT_TABLE)->insert([
                        'article_id' => $duplicateId,
                        'snapshot' => json_encode([
                            'article' => (array) $article,
                            'analysis_ids' => $analysisIds,
                        ], JSON_THROW_ON_ERROR),
                    ]);

                    DB::table('analyses')
                        ->where('article_id', $duplicateId)
                        ->update(['article_id' => $survivorId]);

                    DB::table('articles')->where('id', $duplicateId)->delete();
                }
            }

            Schema::table('articles', function (Blueprint $table): void {
                $table->unique(['created_by', 'canonical_url'], self::CANONICAL_URL_INDEX);
                $table->unique(['created_by', 'content_hash'], self::CONTENT_HASH_INDEX);
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('articles', function (Blueprint $table): void {
                $table->dropUnique(self::CANONICAL_URL_INDEX);
                $table->dropUnique(self::CONTENT_HASH_INDEX);
            });

            foreach (DB::table(self::SNAPSHOT_TABLE)->orderBy('article_id')->get() as $row) {
                $snapshot = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $article = $snapshot['article'];

                if ($article['created_by'] !== null
                    && ! DB::table('users')->where('id', $article['created_by'])->exists()) {
                    $article['created_by'] = null;
                }

                if ($article['source_id'] !== null
                    && ! DB::table('sources')->where('id', $article['source_id'])->exists()) {
                    $article['source_id'] = null;
                }

                DB::table('articles')->insert($article);

                if ($snapshot['analysis_ids'] !== []) {
                    DB::table('analyses')
                        ->whereIn('id', $snapshot['analysis_ids'])
                        ->update(['article_id' => $row->article_id]);
                }
            }

            Schema::drop(self::SNAPSHOT_TABLE);
        });
    }

    /**
     * @return list<list<int>>
     */
    private function duplicateComponents(): array
    {
        $parent = [];
        $find = function (int $id) use (&$parent, &$find): int {
            if ($parent[$id] !== $id) {
                $parent[$id] = $find($parent[$id]);
            }

            return $parent[$id];
        };
        $union = function (int $left, int $right) use (&$parent, $find): void {
            $leftRoot = $find($left);
            $rightRoot = $find($right);

            if ($leftRoot !== $rightRoot) {
                $parent[$rightRoot] = $leftRoot;
            }
        };

        foreach (['canonical_url', 'content_hash'] as $column) {
            $duplicateKeys = DB::table('articles')
                ->select('created_by', $column)
                ->whereNotNull('created_by')
                ->whereNotNull($column)
                ->groupBy('created_by', $column)
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicateKeys as $key) {
                $ids = DB::table('articles')
                    ->where('created_by', $key->created_by)
                    ->where($column, $key->{$column})
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($ids as $id) {
                    $parent[$id] ??= $id;
                }

                foreach (array_slice($ids, 1) as $id) {
                    $union($ids[0], $id);
                }
            }
        }

        $components = [];
        foreach (array_keys($parent) as $id) {
            $components[$find($id)][] = $id;
        }

        return array_values(array_filter($components, fn (array $ids): bool => count($ids) > 1));
    }

    /**
     * @param  list<list<int>>  $components
     */
    private function assertNoAnalysisCollisions(array $components): void
    {
        $collisions = [];

        foreach ($components as $component) {
            $conflicts = DB::table('analyses')
                ->whereIn('article_id', $component)
                ->whereNotNull('user_id')
                ->get(['id', 'article_id', 'user_id'])
                ->groupBy('user_id')
                ->filter(fn ($analyses): bool => $analyses->count() > 1);

            foreach ($conflicts as $userId => $analyses) {
                $collisions[] = sprintf(
                    'articles [%s], user %s, analyses [%s]',
                    implode(', ', $component),
                    $userId,
                    $analyses->pluck('id')->implode(', '),
                );
            }
        }

        if ($collisions !== []) {
            throw new RuntimeException(
                'Article deduplication was aborted because moving analyses would violate per-user uniqueness: '
                .implode('; ', $collisions)
                .'. No article or analysis data was changed.'
            );
        }
    }
};
