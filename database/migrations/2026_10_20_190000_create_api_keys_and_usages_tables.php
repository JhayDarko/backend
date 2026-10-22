<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->string('plan', 20)->default('free');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('analyses')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamps();
            $table->unique(['api_key_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usages');
        Schema::dropIfExists('api_keys');
    }
};
