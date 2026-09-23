<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::table('claims', function (Blueprint $table) use ($driver) {
            if (! Schema::hasColumn('claims', 'type')) {
                $table->string('type')->nullable()->after('text');
            }

            if (! Schema::hasColumn('claims', 'importance')) {
                $table->decimal('importance', 5, 4)->nullable()->after('type');
            }

            if (! Schema::hasColumn('claims', 'verifiable')) {
                $table->boolean('verifiable')->default(false)->after('importance');
            }

            if (! Schema::hasColumn('claims', 'context')) {
                $table->text('context')->nullable()->after('verifiable');
            }

            if (! Schema::hasColumn('claims', 'fingerprint')) {
                $table->string('fingerprint', 128)->nullable()->after('context');
            }

            if (! Schema::hasColumn('claims', 'metadata')) {
                if ($driver === 'sqlite') {
                    $table->text('metadata')->nullable()->after('position');
                } else {
                    $table->jsonb('metadata')->nullable()->after('position');
                }
            }
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->index('type', 'claims_type_index');
            $table->index('verifiable', 'claims_verifiable_index');
            $table->index('fingerprint', 'claims_fingerprint_index');

            if (! Schema::hasIndex('claims', 'claims_analysis_fingerprint_unique')) {
                $table->unique(['analysis_id', 'fingerprint'], 'claims_analysis_fingerprint_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            if (Schema::hasIndex('claims', 'claims_analysis_fingerprint_unique')) {
                $table->dropUnique('claims_analysis_fingerprint_unique');
            }
            if (Schema::hasIndex('claims', 'claims_type_index')) {
                $table->dropIndex('claims_type_index');
            }
            if (Schema::hasIndex('claims', 'claims_verifiable_index')) {
                $table->dropIndex('claims_verifiable_index');
            }
            if (Schema::hasIndex('claims', 'claims_fingerprint_index')) {
                $table->dropIndex('claims_fingerprint_index');
            }

            if (Schema::hasColumn('claims', 'fingerprint')) {
                $table->dropColumn('fingerprint');
            }
            if (Schema::hasColumn('claims', 'context')) {
                $table->dropColumn('context');
            }
            if (Schema::hasColumn('claims', 'verifiable')) {
                $table->dropColumn('verifiable');
            }
            if (Schema::hasColumn('claims', 'importance')) {
                $table->dropColumn('importance');
            }
            if (Schema::hasColumn('claims', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::hasColumn('claims', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
