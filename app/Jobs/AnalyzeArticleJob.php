<?php

namespace App\Jobs;

use App\Models\Analysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzeArticleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 30;

    public function __construct(
        public int $analysisId
    ) {}

    public function handle(): void
    {
        $analysis = Analysis::findOrFail($this->analysisId);

        $analysis->update([
            'status' => 'processing',
            'started_at' => now(),
            'error' => null,
        ]);

        try {
            Log::info('Enqueuing claim extraction', [
                'analysis_id' => $analysis->id,
                'article_id' => $analysis->article_id,
            ]);

            ExtractClaimsJob::dispatch($analysis->id)->onConnection('redis');

            Log::info('Claim extraction queued', [
                'analysis_id' => $analysis->id,
            ]);
        } catch (Throwable $exception) {
            $analysis->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('AnalyzeArticleJob failed', [
                'analysis_id' => $analysis->id,
                'error' => $exception->getMessage(),
            ]);

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

        Log::error('AnalyzeArticleJob permanently failed', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);
    }
}
