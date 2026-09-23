<?php

namespace App\Jobs;

use App\Models\Analysis;
use App\Models\Score;
use App\Services\AI\GeminiAnalystService;
use App\Services\Scoring\CredibilityScoreService;
use App\Services\Source\SourceAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $analysisId,
    ) {}

    public function handle(
        SourceAnalysisService $sourceAnalysisService,
        GeminiAnalystService $geminiAnalystService,
        CredibilityScoreService $credibilityScoreService,
    ): void {
        $analysis = Analysis::query()->find($this->analysisId);

        if (! $analysis) {
            Log::warning('FinalizeAnalysisJob skipped because analysis was not found', [
                'analysis_id' => $this->analysisId,
            ]);

            return;
        }

        if ($analysis->score()->exists()) {
            Log::info('FinalizeAnalysisJob skipped because analysis already has a score', [
                'analysis_id' => $analysis->id,
            ]);

            return;
        }

        $article = $analysis->article;
        if (! $article) {
            $analysis->update([
                'status' => 'failed',
                'error' => 'Analysis has no attached article.',
                'completed_at' => now(),
            ]);

            throw new \RuntimeException('Analysis has no attached article.');
        }

        try {
            Log::info('Finalizing analysis with Source Analysis, Gemini Analyst, and CredibilityScoreService', [
                'analysis_id' => $analysis->id,
                'article_id' => $article->id,
            ]);

            // 1. Source Analysis
            $sourceAnalysis = $sourceAnalysisService->analyze($article->source, $article->url);

            // 2. Gemini Analyst Synthesis (qualitative semantic evaluation)
            $analystSynthesis = null;
            try {
                $analystSynthesis = $geminiAnalystService->analyze($analysis);
            } catch (Throwable $analystException) {
                Log::warning('Gemini Analyst synthesis skipped or failed, falling back to deterministic scoring without AI synthesis', [
                    'analysis_id' => $analysis->id,
                    'error' => $analystException->getMessage(),
                ]);
            }

            // 3. Credibility Score & Verdict Calculation
            $scoreResult = $credibilityScoreService->calculate(
                analysis: $analysis,
                sourceAnalysis: $sourceAnalysis,
                analystSynthesis: $analystSynthesis,
            );

            // 4. Persist Score record idempotently
            Score::query()->updateOrCreate([
                'analysis_id' => $analysis->id,
            ], [
                'evidence_score' => $scoreResult->evidenceScore,
                'source_quality_score' => $scoreResult->sourceQualityScore,
                'corroboration_score' => $scoreResult->corroborationScore,
                'independence_score' => $scoreResult->independenceScore,
                'consistency_score' => $scoreResult->consistencyScore,
                'language_score' => $scoreResult->languageScore,
                'transparency_score' => $scoreResult->transparencyScore,
                'final_score' => $scoreResult->finalScore,
                'algorithm_version' => $scoreResult->algorithmVersion,
                'metadata' => $scoreResult->breakdown,
            ]);

            // 5. Update Analysis record to completed
            $analysis->update([
                'status' => 'completed',
                'credibility_score' => $scoreResult->finalScore,
                'confidence_score' => $scoreResult->confidenceScore,
                'final_verdict' => $scoreResult->finalVerdict,
                'completed_at' => now(),
                'error' => null,
            ]);

            Log::info('Analysis successfully finalized', [
                'analysis_id' => $analysis->id,
                'final_score' => $scoreResult->finalScore,
                'final_verdict' => $scoreResult->finalVerdict,
                'confidence_score' => $scoreResult->confidenceScore,
            ]);
        } catch (Throwable $exception) {
            $analysis->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);

            Log::error('FinalizeAnalysisJob failed', [
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

        Log::error('FinalizeAnalysisJob permanently failed', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);
    }
}
