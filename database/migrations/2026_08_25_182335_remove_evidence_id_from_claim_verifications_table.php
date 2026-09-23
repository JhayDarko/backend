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
        Schema::table('claim_verifications', function (Blueprint $table) {
            $table->dropForeign(['evidence_id']);
            $table->dropUnique(['claim_id', 'evidence_id']);
            $table->dropColumn('evidence_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claim_verifications', function (Blueprint $table) {
            $table->foreignId('evidence_id')
                ->nullable()
                ->after('claim_id')
                ->constrained('evidences')
                ->cascadeOnDelete();

            $table->unique(['claim_id', 'evidence_id']);
        });
    }
};
