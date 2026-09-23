<?php

namespace App\Services\Scoring;

use App\DTO\Analyst\AnalystSynthesisResult;
use App\DTO\Scoring\ScoreResult;
use App\DTO\Source\SourceAnalysisResult;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Models\ModelPrediction;
use Illuminate\Support\Collection;

class CredibilityScoreService
{
    /**
     * Calculate deterministic credibility score and verdict from all available analysis signals.
     */
    public function calculate(
        Analysis $analysis,
        SourceAnalysisResult $sourceAnalysis,
        ?AnalystSynthesisResult $analystSynthesis = null,
    ): ScoreResult {
        $article = $analysis->article;
        $claims = $analysis->claims()->get();
        $prediction = $analysis->predictions()->latest()->first();

        $claimIds = $claims->pluck('id')->all();
        $verifications = ClaimVerification::query()->whereIn('claim_id', $claimIds)->get();
        $verificationIds = $verifications->pluck('id')->all();

        $evidences = Evidence::query()
            ->whereHas('claimVerifications', fn ($q) => $q->whereIn('claim_verifications.id', $verificationIds))
            ->get();

        // 1. Calculate individual component subscores (0 to 100)
        $evidenceScore = $this->calculateEvidenceScore($claims, $verifications, $evidences);
        $sourceQualityScore = $this->calculateSourceQualityScore($sourceAnalysis);
        $corroborationScore = $this->calculateCorroborationScore($verifications, $evidences);
        $independenceScore = $this->calculateIndependenceScore($evidences);
        $consistencyScore = $this->calculateConsistencyScore($claims, $analystSynthesis);
        $languageScore = $this->calculateLanguageScore($prediction);
        $transparencyScore = $this->calculateTransparencyScore($article, $claims);

        // 2. Retrieve weights from configuration
        $weights = [
            'evidence' => (float) config('scoring.weights.evidence', 0.25),
            'language' => (float) config('scoring.weights.language', 0.20),
            'source_quality' => (float) config('scoring.weights.source_quality', 0.15),
            'corroboration' => (float) config('scoring.weights.corroboration', 0.15),
            'independence' => (float) config('scoring.weights.independence', 0.10),
            'consistency' => (float) config('scoring.weights.consistency', 0.15),
            'transparency' => (float) config('scoring.weights.transparency', 0.10),
        ];

        // Normalize weights if they do not sum to 1.0
        $totalWeight = array_sum($weights);
        if ($totalWeight > 0 && abs($totalWeight - 1.0) > 0.001) {
            foreach ($weights as $k => $w) {
                $weights[$k] = $w / $totalWeight;
            }
        }

        // 3. Compute weighted final score (0 to 100)
        $finalScore = ($evidenceScore * $weights['evidence'])
            + ($languageScore * $weights['language'])
            + ($sourceQualityScore * $weights['source_quality'])
            + ($corroborationScore * $weights['corroboration'])
            + ($independenceScore * $weights['independence'])
            + ($consistencyScore * $weights['consistency'])
            + ($transparencyScore * $weights['transparency']);

        $finalScore = max(0.0, min(100.0, round($finalScore, 2)));

        // 4. Map to final verdict
        $finalVerdict = $this->determineVerdict($finalScore);

        // 5. Compute confidence score (0.0 to 1.0)
        $confidenceScore = $this->calculateConfidenceScore($claims, $verifications, $evidences, $prediction, $analystSynthesis);

        $algorithmVersion = (string) config('scoring.algorithm_version', '1.0.0');

        $breakdown = [
            'weights' => $weights,
            'signals' => [
                'claims_count' => $claims->count(),
                'verifications_count' => $verifications->count(),
                'evidences_count' => $evidences->count(),
                'has_bert_prediction' => $prediction !== null,
                'has_analyst_synthesis' => $analystSynthesis !== null,
            ],
            'source_analysis' => $sourceAnalysis->toArray(),
            'analyst_summary' => $analystSynthesis?->summary,
            'key_findings' => $analystSynthesis?->keyFindings ?? [],
            'fallacies_and_red_flags' => $analystSynthesis?->fallaciesAndRedFlags ?? [],
            'bias_indicators' => $analystSynthesis?->biasIndicators ?? [],
        ];

        return new ScoreResult(
            evidenceScore: round($evidenceScore, 2),
            sourceQualityScore: round($sourceQualityScore, 2),
            corroborationScore: round($corroborationScore, 2),
            independenceScore: round($independenceScore, 2),
            consistencyScore: round($consistencyScore, 2),
            languageScore: round($languageScore, 2),
            transparencyScore: round($transparencyScore, 2),
            finalScore: $finalScore,
            finalVerdict: $finalVerdict,
            confidenceScore: round($confidenceScore, 2),
            algorithmVersion: $algorithmVersion,
            breakdown: $breakdown,
        );
    }

    /**
     * Calculate evidence score from Fact-Checks & search results (0 to 100).
     *
     * @param  Collection<int, Claim>  $claims
     * @param  Collection<int, ClaimVerification>  $verifications
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateEvidenceScore(
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
    ): float {
        $factCheckRatings = config('scoring.fact_check_rating_scores', [
            'true' => 100.0,
            'mostly_true' => 80.0,
            'mixed' => 50.0,
            'mostly_false' => 20.0,
            'false' => 0.0,
            'unknown' => 50.0,
        ]);

        $factCheckVerifications = $verifications->filter(
            fn (ClaimVerification $v) => $v->status === 'matched'
                && ($v->provider === 'google_fact_check' || $v->relation === 'external_fact_check')
        );

        if ($factCheckVerifications->isNotEmpty()) {
            $total = 0.0;
            $count = 0;

            foreach ($factCheckVerifications as $fv) {
                $rating = strtolower((string) ($fv->normalized_rating ?? 'unknown'));
                $ratingScore = $factCheckRatings[$rating] ?? 50.0;
                $total += $ratingScore;
                $count++;
            }

            return $count > 0 ? ($total / $count) : 50.0;
        }

        // If no explicit fact-check exists, evaluate based on search evidence volume and relevance
        if ($evidences->isNotEmpty()) {
            // Having corroborated web evidence gives a solid baseline between 50 and 75
            $evidenceCount = min($evidences->count(), 10);

            return 50.0 + ($evidenceCount * 2.5); // up to 75.0
        }

        // Default neutral score when unverified
        return 50.0;
    }

    /**
     * Calculate source quality score (0 to 100).
     */
    protected function calculateSourceQualityScore(SourceAnalysisResult $sourceAnalysis): float
    {
        return round($sourceAnalysis->reputationScore * 100.0, 2);
    }

    /**
     * Calculate corroboration score (0 to 100).
     *
     * @param  Collection<int, ClaimVerification>  $verifications
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateCorroborationScore(Collection $verifications, Collection $evidences): float
    {
        if ($evidences->isEmpty() && $verifications->isEmpty()) {
            return 50.0;
        }

        $evidenceCount = $evidences->count();
        $verificationCount = $verifications->where('status', 'matched')->count();

        // Scale by number of corroborating sources found
        $score = 40.0 + (min($evidenceCount, 6) * 7.0) + (min($verificationCount, 4) * 4.5);

        return min(100.0, max(0.0, $score));
    }

    /**
     * Calculate independence score based on distinct evidence domains (0 to 100).
     *
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateIndependenceScore(Collection $evidences): float
    {
        if ($evidences->isEmpty()) {
            return 50.0;
        }

        $domains = [];
        foreach ($evidences as $evidence) {
            $url = $evidence->url ?? '';
            $domain = $evidence->metadata['domain'] ?? parse_url($url, PHP_URL_HOST);
            if ($domain) {
                $domains[strtolower(trim($domain))] = true;
            }
        }

        $uniqueDomainsCount = count($domains);

        if ($uniqueDomainsCount <= 1) {
            return 45.0;
        }

        // 2 distinct domains = 70, 3 = 85, 4+ = 100
        $score = 50.0 + min(50.0, ($uniqueDomainsCount - 1) * 17.0);

        return min(100.0, $score);
    }

    /**
     * Calculate consistency score (0 to 100).
     *
     * @param  Collection<int, Claim>  $claims
     */
    protected function calculateConsistencyScore(Collection $claims, ?AnalystSynthesisResult $analystSynthesis): float
    {
        if ($analystSynthesis !== null) {
            return round($analystSynthesis->consistencyScore * 100.0, 2);
        }

        // Fallback: ratio of verifiable claims
        if ($claims->isEmpty()) {
            return 50.0;
        }

        $verifiableCount = $claims->where('verifiable', true)->count();
        $ratio = $verifiableCount / max(1, $claims->count());

        return round(40.0 + ($ratio * 60.0), 2);
    }

    /**
     * Calculate language score based on BERT classifier prediction (0 to 100).
     */
    protected function calculateLanguageScore(?ModelPrediction $prediction): float
    {
        if (! $prediction) {
            return 50.0; // Neutral baseline when BERT is not available
        }

        $label = strtoupper(trim((string) $prediction->prediction_label));
        $prob = (float) ($prediction->probability ?? $prediction->confidence_score ?? 0.5);

        if ($label === 'REAL') {
            // REAL probability maps to 50.0 - 100.0
            return 50.0 + ($prob * 50.0);
        }

        if ($label === 'FAKE') {
            // FAKE probability maps to 50.0 - 0.0
            return max(0.0, 50.0 - ($prob * 50.0));
        }

        return 50.0;
    }

    /**
     * Calculate transparency score based on article attribution and claim verifiability (0 to 100).
     *
     * @param  Collection<int, Claim>  $claims
     */
    protected function calculateTransparencyScore(?Article $article, Collection $claims): float
    {
        $score = 30.0; // Baseline

        if (! $article) {
            return $score;
        }

        // 1. Author presence (+20)
        if (! empty($article->author_name) || ! empty($article->author)) {
            $score += 20.0;
        }

        // 2. Canonical URL or valid source URL (+20)
        if (! empty($article->canonical_url) || ! empty($article->url)) {
            $score += 20.0;
        }

        // 3. Claims verifiability (+30)
        if ($claims->isNotEmpty()) {
            $verifiableRatio = $claims->where('verifiable', true)->count() / $claims->count();
            $score += ($verifiableRatio * 30.0);
        }

        return min(100.0, max(0.0, $score));
    }

    /**
     * Determine final verbal verdict from numeric final score.
     */
    protected function determineVerdict(float $finalScore): string
    {
        $thresholds = config('scoring.thresholds', [
            'credible' => 85.0,
            'probably_credible' => 65.0,
            'inconclusive' => 45.0,
            'probably_misleading' => 25.0,
        ]);

        if ($finalScore >= ($thresholds['credible'] ?? 85.0)) {
            return 'credible';
        }

        if ($finalScore >= ($thresholds['probably_credible'] ?? 65.0)) {
            return 'probably_credible';
        }

        if ($finalScore >= ($thresholds['inconclusive'] ?? 45.0)) {
            return 'inconclusive';
        }

        if ($finalScore >= ($thresholds['probably_misleading'] ?? 25.0)) {
            return 'probably_misleading';
        }

        return 'false';
    }

    /**
     * Compute confidence score between 0.0 and 1.0.
     *
     * @param  Collection<int, Claim>  $claims
     * @param  Collection<int, ClaimVerification>  $verifications
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateConfidenceScore(
        Collection $claims,
        Collection $verifications,
        Collection $evidences,
        ?ModelPrediction $prediction,
        ?AnalystSynthesisResult $analystSynthesis,
    ): float {
        $confidence = 0.30; // Baseline

        if ($prediction !== null) {
            $confidence += 0.20;
        }

        if ($analystSynthesis !== null) {
            $confidence += 0.20;
        }

        if ($verifications->where('status', 'matched')->isNotEmpty()) {
            $confidence += 0.15;
        }

        if ($evidences->isNotEmpty()) {
            $confidence += 0.15;
        }

        return min(1.0, round($confidence, 2));
    }
}
