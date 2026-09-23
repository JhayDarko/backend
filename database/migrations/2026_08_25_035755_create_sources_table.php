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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('domain')->unique();
            $table->string('url')->nullable();

            $table->string('type')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('language', 10)->nullable();

            $table->text('description')->nullable();

            $table->decimal('reputation_score', 5, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
