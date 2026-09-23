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
        Schema::create('verification_evidence', function (Blueprint $table) {
            $table->foreignId('claim_verification_id')
                ->constrained('claim_verifications')
                ->cascadeOnDelete();

            $table->foreignId('evidence_id')
                ->constrained('evidences')
                ->cascadeOnDelete();

            $table->primary(['claim_verification_id', 'evidence_id']);
            $table->unique(['claim_verification_id', 'evidence_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_evidence');
    }
};
