<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateUrls = DB::table('evidences')
            ->select('url')
            ->groupBy('url')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('url');

        foreach ($duplicateUrls as $url) {
            $ids = DB::table('evidences')->where('url', $url)->orderBy('id')->pluck('id');
            $keepId = $ids->shift();

            foreach ($ids as $duplicateId) {
                $links = DB::table('verification_evidence')
                    ->where('evidence_id', $duplicateId)
                    ->get();

                foreach ($links as $link) {
                    DB::table('verification_evidence')->updateOrInsert([
                        'claim_verification_id' => $link->claim_verification_id,
                        'evidence_id' => $keepId,
                    ], [
                        'created_at' => $link->created_at ?? now(),
                        'updated_at' => $link->updated_at ?? now(),
                    ]);
                }

                DB::table('verification_evidence')->where('evidence_id', $duplicateId)->delete();
                DB::table('evidences')->where('id', $duplicateId)->delete();
            }
        }

        Schema::table('evidences', function (Blueprint $table): void {
            $table->unique('url', 'evidences_url_unique');
        });

        Schema::table('claim_verifications', function (Blueprint $table): void {
            $table->unique('fingerprint', 'claim_verifications_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::table('claim_verifications', function (Blueprint $table): void {
            $table->dropUnique('claim_verifications_fingerprint_unique');
        });

        Schema::table('evidences', function (Blueprint $table): void {
            $table->dropUnique('evidences_url_unique');
        });
    }
};
