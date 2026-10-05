<?php

namespace App\Jobs;

use App\Contracts\AiProviderInterface;
use App\Exceptions\AiProviderException;
use App\Models\Analysis;
use App\Services\Verification\ClaimExtractionService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractClaimsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public array $backoff = [60, 180, 600, 900];

    public int $timeout = 180;

    public function __construct(
        public int $analysisId,
    ) {}

    public function handle(ClaimExtractionService $claimExtractionService): void
    {
        $analysis = Analysis::query()->find($this->analysisId);

        if (! $analysis) {
            Log::warning('ExtractClaimsJob skipped because analysis was not found', [
                'analysis_id' => $this->analysisId,
            ]);

            return;
        }

        if ($analysis->score()->exists()) {
            Log::info('ExtractClaimsJob skipped because analysis has already been finalized', [
                'analysis_id' => $analysis->id,
            ]);

            return;
        }

        $article = $analysis->article()->first();

        if (! $article) {
            $analysis->update([
                'status' => 'failed',
                'error' => 'Analysis has no attached article.',
                'completed_at' => now(),
            ]);

            throw new \RuntimeException('Analysis has no attached article.');
        }

        $this->markProcessing($analysis);

        try {
            $result = $claimExtractionService->extract($article);

            $persisted = $claimExtractionService->persistClaims($analysis, $result);

            $analysis->refresh();

            $analysis->update([
                'model_provider' => app(AiProviderInterface::class)->getName(),
                'model_name' => app(AiProviderInterface::class)->getModel(),
                'algorithm_version' => config('ai.prompt_version', '1.0.0'),
            ]);

            $claims = $analysis->claims()->get();

            $jobs = [new ClassifyArticleJob($analysis->id)->onConnection('redis')];
            foreach ($claims->where('verifiable', true) as $claim) {
                $jobs[] = new VerifyClaimJob($claim->id)->onConnection('redis');
                $jobs[] = new SearchClaimEvidenceJob($claim->id)->onConnection('redis');
            }

            $analysisId = $analysis->id;
            Bus::batch($jobs)
                ->name("analysis-{$analysisId}-evidence")
                ->allowFailures()
                ->then(function (Batch $batch) use ($analysisId): void {
                    FinalizeAnalysisJob::dispatch($analysisId)->onConnection('redis');
                })
                ->catch(function (Batch $batch, Throwable $exception) use ($analysisId): void {
                    Log::warning("Batch error in evidence collection for analysis {$analysisId}", [
                        'error' => $exception->getMessage(),
                    ]);
                })
                ->finally(function (Batch $batch) use ($analysisId): void {
                    if ($batch->hasFailures()) {
                        FinalizeAnalysisJob::dispatch($analysisId)->onConnection('redis');
                    }
                })
                ->dispatch();

            Log::info('Claims extracted and persisted', [
                'analysis_id' => $analysis->id,
                'article_id' => $article->id,
                'persisted_claims' => $persisted,
                'batch_jobs_dispatched' => count($jobs),
            ]);
        } catch (Throwable $exception) {
            $analysis->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('ExtractClaimsJob failed', [
                'analysis_id' => $analysis->id,
                'article_id' => $article->id,
                'error' => $exception->getMessage(),
            ]);

            if ($exception instanceof AiProviderException && $exception->getCode() === 402) {
                Log::warning('ExtractClaimsJob stopped retrying because an AI provider has no available quota', [
                    'analysis_id' => $analysis->id,
                    'article_id' => $article->id,
                ]);

                return;
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $analysis = Analysis::query()->find($this->analysisId);

        if ($analysis) {
            $analysis->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }

        Log::error('ExtractClaimsJob permanently failed', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function markProcessing(Analysis $analysis): void
    {
        $analysis->update([
            'status' => 'processing',
            'started_at' => now(),
            'error' => null,
        ]);
    }
}
