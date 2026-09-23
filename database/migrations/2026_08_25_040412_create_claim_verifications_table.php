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
        Schema::create('claim_verifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('claim_id')
                ->constrained('claims')
                ->cascadeOnDelete();

            $table->foreignId('evidence_id')
                ->constrained('evidences')
                ->cascadeOnDelete();

            $table->string('relation');

            $table->decimal('relevance_score', 5, 2)->nullable();

            $table->decimal('confidence', 5, 2)->nullable();

            $table->text('explanation')->nullable();

            $table->timestamps();

            $table->unique(['claim_id', 'evidence_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claim_verifications');
    }
};
