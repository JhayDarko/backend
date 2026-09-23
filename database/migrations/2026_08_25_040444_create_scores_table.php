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
        Schema::create('scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_id')
                ->unique()
                ->constrained('analyses')
                ->cascadeOnDelete();

            $table->decimal('evidence_score', 5, 2)->nullable();
            $table->decimal('source_quality_score', 5, 2)->nullable();
            $table->decimal('corroboration_score', 5, 2)->nullable();
            $table->decimal('independence_score', 5, 2)->nullable();
            $table->decimal('consistency_score', 5, 2)->nullable();
            $table->decimal('language_score', 5, 2)->nullable();
            $table->decimal('transparency_score', 5, 2)->nullable();

            $table->decimal('final_score', 5, 2);

            $table->string('algorithm_version');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
