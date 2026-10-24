<?php

namespace App\Jobs;

use App\Contracts\ClassifierProviderInterface;
use App\Models\Analysis;
use App\Models\ModelPrediction;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyArticleJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 180;

    public function __construct(
        public int $analysisId,
    ) {}

    public function handle(?ClassifierProviderInterface $classifierProvider = null): void
    {
        $classifierProvider ??= app(ClassifierProviderInterface::class);

        $analysis = Analysis::query()->find($this->analysisId);

        if (! $analysis) {
            Log::warning('ClassifyArticleJob skipped because analysis was not found', [
                'analysis_id' => $this->analysisId,
            ]);

            return;
        }

        if ($analysis->predictions()->exists()) {
            Log::info('ClassifyArticleJob skipped because prediction already exists', [
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

        try {
            $result = $classifierProvider->classify(
                '',
                $article->content ?? '',
                $article->description ?? null,
            );

            ModelPrediction::query()->create([
                'analysis_id' => $analysis->id,
                'prediction_label' => $result->label,
                'confidence_score' => $result->confidence,
                'probability' => $result->probability,
                'model_provider' => $classifierProvider->getName(),
                'model_name' => $classifierProvider->getModel(),
                'algorithm_version' => config('ai.bert.version', '1.0.0'),
                'metadata' => [
                    'source' => 'bert_fake_news_classifier',
                    'raw' => $result->rawData,
                ],
            ]);

            Log::info('Article classified with BERT', [
                'analysis_id' => $analysis->id,
                'article_id' => $article->id,
                'label' => $result->label,
                'confidence' => $result->confidence,
            ]);
        } catch (Throwable $exception) {
            Log::error('ClassifyArticleJob failed', [
                'analysis_id' => $analysis->id,
                'article_id' => $article->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $analysis = Analysis::query()->find($this->analysisId);

        if ($analysis && $analysis->article()->exists()) {
            $metadata = $analysis->metadata ?? [];
            $metadata['classifier_unavailable'] = true;

            $analysis->update(['metadata' => $metadata]);
        }

        Log::error('ClassifyArticleJob permanently failed', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);
    }
}
