<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_id')
                ->nullable()
                ->constrained('sources')
                ->nullOnDelete();

            $table->text('url');
            $table->text('canonical_url')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->longText('content')->nullable();

            $table->string('author_name')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->string('language', 10)->nullable();

            $table->string('content_hash', 64)->nullable()->index();

            $table->jsonb('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
