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
        Schema::create('evidences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_id')
                ->nullable()
                ->constrained('sources')
                ->nullOnDelete();

            $table->text('url');

            $table->text('title')->nullable();

            $table->longText('content')->nullable();

            $table->text('snippet')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamp('retrieved_at')->nullable();

            $table->string('content_hash', 64)->nullable()->index();

            $table->decimal('similarity', 6, 5)->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evidences');
    }
};
