<?php

namespace App\Jobs;

use App\Models\Claim;
use App\Services\Search\EvidenceSearchService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SearchClaimEvidenceJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $claimId,
    ) {}

    public function handle(EvidenceSearchService $evidenceSearchService): void
    {
        $claim = Claim::query()->find($this->claimId);

        if (! $claim) {
            Log::warning('SearchClaimEvidenceJob skipped because claim was not found', [
                'claim_id' => $this->claimId,
            ]);

            return;
        }

        if (! $claim->verifiable) {
            Log::info('SearchClaimEvidenceJob skipped because claim is not verifiable', [
                'claim_id' => $claim->id,
            ]);

            return;
        }

        try {
            $results = $evidenceSearchService->persistForClaim($claim);

            Log::info('SearchClaimEvidenceJob completed', [
                'claim_id' => $claim->id,
                'results_count' => count($results),
                'provider' => $evidenceSearchService->buildQuery($claim),
            ]);
        } catch (Throwable $exception) {
            Log::error('SearchClaimEvidenceJob failed', [
                'claim_id' => $claim->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SearchClaimEvidenceJob permanently failed', [
            'claim_id' => $this->claimId,
            'error' => $exception->getMessage(),
        ]);
    }
}
