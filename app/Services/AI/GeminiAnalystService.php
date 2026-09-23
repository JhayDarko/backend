<?php

namespace App\Services\AI;

use App\Contracts\Analyst\AnalystProviderInterface;
use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Models\Analysis;
use App\Models\Article;
use App\Services\Source\SourceAnalysisService;

class GeminiAnalystService
{
    public function __construct(
        protected AnalystProviderInterface $provider,
        protected SourceAnalysisService $sourceAnalysisService,
    ) {}

    public function analyze(Analysis $analysis): AnalystSynthesisResult
    {
        $article = $analysis->article;
        if (! $article) {
            throw new \RuntimeException("Analysis #{$analysis->id} has no associated article.");
        }

        $source = $article->source;
        $sourceAnalysis = $this->sourceAnalysisService->analyze($source, $article->url);

        $claims = $analysis->claims()->get();
        $claimIds = $claims->pluck('id')->all();

        $verifications = \App\Models\ClaimVerification::query()
            ->whereIn('claim_id', $claimIds)
            ->get();

        $verificationIds = $verifications->pluck('id')->all();

        $evidences = \App\Models\Evidence::query()
            ->whereHas('claimVerifications', function ($query) use ($verificationIds) {
                $query->whereIn('claim_verifications.id', $verificationIds);
            })
            ->get();

        $prediction = $analysis->predictions()->latest()->first();

        return $this->provider->synthesize(
            article: $article,
            claims: $claims,
            verifications: $verifications,
            evidences: $evidences,
            prediction: $prediction,
            sourceAnalysis: $sourceAnalysis,
        );
    }
}

