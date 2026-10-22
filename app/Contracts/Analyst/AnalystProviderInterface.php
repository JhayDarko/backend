<?php

namespace App\Contracts\Analyst;

use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Exceptions\AiProviderException;
use App\Models\Article;
use App\Models\ModelPrediction;
use Illuminate\Support\Collection;

interface AnalystProviderInterface
{
    /**
     * Synthesize qualitative analysis from article, claims, verifications, evidences, model predictions and source.
     *
     *
     * @throws AiProviderException
     */
    public function synthesize(
        Article $article,
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        SourceAnalysisResult $sourceAnalysis,
    ): AnalystSynthesisResult;

    /**
     * Get provider name.
     */
    public function getName(): string;

    /**
     * Get model name currently in use.
     */
    public function getModel(): string;
}
