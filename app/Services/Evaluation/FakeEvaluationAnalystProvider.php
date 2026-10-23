<?php

namespace App\Services\Evaluation;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Models\Article;
use App\Models\ModelPrediction;
use Illuminate\Support\Collection;

final class FakeEvaluationAnalystProvider implements AnalystProviderInterface
{
    public function synthesize(
        Article $article,
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        SourceAnalysisResult $sourceAnalysis,
    ): AnalystSynthesisResult {
        throw new \RuntimeException('Synthesis disabled in evaluation smoke test.');
    }

    public function getName(): string
    {
        return 'evaluation_fake';
    }

    public function getModel(): string
    {
        return 'evaluation-fake-analyst-v1';
    }
}
