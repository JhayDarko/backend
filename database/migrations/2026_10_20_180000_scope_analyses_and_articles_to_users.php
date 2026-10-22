<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analyses', function (Blueprint $table) {
            $table->dropUnique('analyses_article_id_unique');
            $table->unique(['article_id', 'user_id'], 'analyses_article_user_unique');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('source_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('analyses', function (Blueprint $table) {
            $table->dropUnique('analyses_article_user_unique');
            $table->unique('article_id', 'analyses_article_id_unique');
        });
    }
};
