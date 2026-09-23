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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();

            $table->foreignId('analysis_id')
                ->constrained('analyses')
                ->cascadeOnDelete();

            $table->text('text');

            $table->string('type')->nullable();

            $table->decimal('importance', 5, 2)->nullable();

            $table->string('verdict')->nullable();

            $table->decimal('confidence', 5, 2)->nullable();

            $table->integer('position')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
