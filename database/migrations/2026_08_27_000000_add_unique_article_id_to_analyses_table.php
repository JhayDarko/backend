<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateRows = DB::table('analyses')
            ->select('article_id')
            ->groupBy('article_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('article_id');

        foreach ($duplicateRows as $articleId) {
            $rows = DB::table('analyses')
                ->where('article_id', $articleId)
                ->orderBy('id')
                ->get();

            $keep = $rows->first();

            foreach ($rows->skip(1) as $duplicate) {
                DB::table('analyses')
                    ->where('id', $duplicate->id)
                    ->delete();
            }

            if ($keep !== null) {
                DB::table('analyses')
                    ->where('id', $keep->id)
                    ->update(['updated_at' => now()]);
            }
        }

        if (! Schema::hasIndex('analyses', 'analyses_article_id_unique')) {
            Schema::table('analyses', function (Blueprint $table): void {
                $table->unique('article_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('analyses', 'analyses_article_id_unique')) {
            Schema::table('analyses', function (Blueprint $table): void {
                $table->dropUnique('analyses_article_id_unique');
            });
        }
    }
};