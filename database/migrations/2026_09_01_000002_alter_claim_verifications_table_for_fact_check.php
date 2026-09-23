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
            if (! Schema::hasColumn('claim_verifications', 'status')) {
                $table->string('status')->default('pending')->after('claim_id');
            }

            if (! Schema::hasColumn('claim_verifications', 'fingerprint')) {
                $table->string('fingerprint', 64)->nullable()->after('status');
            }

            if (! Schema::hasColumn('claim_verifications', 'provider')) {
                $table->string('provider')->nullable()->after('fingerprint');
            }

            if (! Schema::hasColumn('claim_verifications', 'query_used')) {
                $table->text('query_used')->nullable()->after('provider');
            }

            if (! Schema::hasColumn('claim_verifications', 'publisher')) {
                $table->string('publisher')->nullable()->after('query_used');
            }

            if (! Schema::hasColumn('claim_verifications', 'publisher_site')) {
                $table->string('publisher_site')->nullable()->after('publisher');
            }

            if (! Schema::hasColumn('claim_verifications', 'fact_check_url')) {
                $table->text('fact_check_url')->nullable()->after('publisher_site');
            }

            if (! Schema::hasColumn('claim_verifications', 'fact_check_title')) {
                $table->text('fact_check_title')->nullable()->after('fact_check_url');
            }

            if (! Schema::hasColumn('claim_verifications', 'raw_rating')) {
                $table->text('raw_rating')->nullable()->after('fact_check_title');
            }

            if (! Schema::hasColumn('claim_verifications', 'normalized_rating')) {
                $table->string('normalized_rating')->nullable()->after('raw_rating');
            }

            if (! Schema::hasColumn('claim_verifications', 'review_date')) {
                $table->timestamp('review_date')->nullable()->after('normalized_rating');
            }

            if (! Schema::hasColumn('claim_verifications', 'language')) {
                $table->string('language')->nullable()->after('review_date');
            }

            if (! Schema::hasColumn('claim_verifications', 'metadata')) {
                $table->jsonb('metadata')->nullable()->after('language');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claim_verifications', function (Blueprint $table) {
            $columns = [
                'status',
                'fingerprint',
                'provider',
                'query_used',
                'publisher',
                'publisher_site',
                'fact_check_url',
                'fact_check_title',
                'raw_rating',
                'normalized_rating',
                'review_date',
                'language',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('claim_verifications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
