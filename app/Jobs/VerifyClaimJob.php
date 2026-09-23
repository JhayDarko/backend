<?php

namespace App\Jobs;

use App\Models\Claim;
use App\Services\FactCheck\FactCheckService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyClaimJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $claimId,
    ) {}

    public function handle(FactCheckService $factCheckService): void
    {
        $claim = Claim::query()->find($this->claimId);

        if (! $claim) {
            Log::warning('VerifyClaimJob skipped because claim was not found', [
                'claim_id' => $this->claimId,
            ]);

            return;
        }

        $existing = $claim->claimVerifications()->where('provider', 'google_fact_check')->exists();

        if ($existing) {
            Log::info('VerifyClaimJob skipped because claim already has external verification', [
                'claim_id' => $claim->id,
            ]);

            return;
        }

        try {
            $result = $factCheckService->verifyClaim($claim);

            Log::info('Claim verification completed', [
                'claim_id' => $claim->id,
                'status' => $result['status'] ?? 'unknown',
                'count' => count($result['results'] ?? []),
            ]);
        } catch (Throwable $exception) {
            Log::error('VerifyClaimJob failed', [
                'claim_id' => $claim->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('VerifyClaimJob permanently failed', [
            'claim_id' => $this->claimId,
            'error' => $exception->getMessage(),
        ]);
    }
}
