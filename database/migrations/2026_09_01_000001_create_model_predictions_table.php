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
        Schema::create('model_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained('analyses')->cascadeOnDelete();
            $table->string('prediction_label');
            $table->decimal('confidence_score', 5, 4)->nullable();
            $table->decimal('probability', 5, 4)->nullable();
            $table->string('model_provider')->nullable();
            $table->string('model_name')->nullable();
            $table->string('algorithm_version')->default('1.0.0');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['analysis_id'], 'model_predictions_analysis_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('model_predictions');
    }
};
