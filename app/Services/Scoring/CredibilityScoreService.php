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
        $verifications = ClaimVerification::query()
            ->whereIn('claim_id', $claimIds)
            ->with('evidences')
            ->get();
        $evidences = $verifications
            ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
            ->unique('id')
            ->values();
        $evaluationByClaim = collect($analystSynthesis?->claimEvaluations ?? [])
            ->filter(fn ($evaluation): bool => $claims->contains('id', $evaluation->claimId))
            ->keyBy('claimId');
        $factCheckRatings = config('scoring.fact_check_rating_scores', []);
        $claimEvaluations = $this->buildClaimAssessments($claims, $verifications, $evaluationByClaim, $factCheckRatings);
        $factualClaims = collect($claimEvaluations)->where('included_in_verdict', true);
        $totalClaimWeight = $factualClaims->sum('importance');
        $evidenceCoverage = $this->calculateCoverage($factualClaims, 'evidence_backed', $totalClaimWeight);
        $factCheckCoverage = $this->calculateCoverage($factualClaims, 'fact_checked', $totalClaimWeight);
        $evidenceScore = $this->calculateWeightedClaimScore($factualClaims, $totalClaimWeight);
        $sourceQualityScore = $this->calculateSourceQualityScore($sourceAnalysis);
        $corroborationScore = round($evidenceCoverage * 100.0, 2);
        $independenceScore = $this->calculateIndependenceScore($claimEvaluations);
        $consistencyScore = $this->calculateConsistencyScore($analystSynthesis);
        $languageScore = $this->calculateLanguageScore($prediction);
        $transparencyScore = $this->calculateTransparencyScore($article, $claims);

        // 2. Retrieve weights from configuration
        $weights = [
            'evidence' => (float) config('scoring.weights.evidence', 0.72),
            'language' => (float) config('scoring.weights.language', 0.03),
            'source_quality' => (float) config('scoring.weights.source_quality', 0.05),
            'corroboration' => (float) config('scoring.weights.corroboration', 0.12),
            'independence' => (float) config('scoring.weights.independence', 0.03),
            'consistency' => (float) config('scoring.weights.consistency', 0.05),
            'transparency' => (float) config('scoring.weights.transparency', 0.0),
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

        $independentFactCheckers = collect($claimEvaluations)
            ->flatMap(fn (array $evaluation) => $evaluation['fact_check_sources'])
            ->pluck('domain')
            ->filter()
            ->unique()
            ->count();
        $confidenceScore = $this->calculateConfidenceScore(
            $evidenceCoverage,
            $factCheckCoverage,
            $independentFactCheckers,
        );
        $finalVerdict = $this->determineVerdict($finalScore, $evidenceCoverage, $factCheckCoverage);
        $truthAssessment = $this->determineTruthAssessment($finalScore, $evidenceCoverage, $factualClaims);
        $algorithmVersion = (string) config('scoring.algorithm_version', '1.2.0');
        $summary = $this->buildArticleSummary($analystSynthesis, $claimEvaluations, $truthAssessment, $evidenceCoverage);
        $verdictRationale = $this->buildVerdictRationale($truthAssessment, $evidenceCoverage, $factCheckCoverage);

        $breakdown = [
            'weights' => $weights,
            'signals' => [
                'claims_count' => $claims->count(),
                'verifications_count' => $verifications->count(),
                'evidences_count' => $evidences->count(),
                'factual_claims_count' => $factualClaims->count(),
                'evidence_coverage' => round($evidenceCoverage, 4),
                'fact_check_coverage' => round($factCheckCoverage, 4),
                'has_bert_prediction' => $prediction !== null,
                'has_analyst_synthesis' => $analystSynthesis !== null,
                'independent_fact_checkers' => $independentFactCheckers,
            ],
            'source_analysis' => $sourceAnalysis->toArray(),
            'analyst_summary' => $summary,
            'truth_assessment' => $truthAssessment,
            'verdict_rationale' => $verdictRationale,
            'suggested_verdict' => $analystSynthesis?->suggestedVerdict,
            'claim_evaluations' => $claimEvaluations,
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
    protected function buildClaimAssessments(
        Collection $claims,
        Collection $verifications,
        Collection $evaluationByClaim,
        array $factCheckRatings,
    ): array {
        $assessments = [];
        $stanceScores = ['supported' => 85.0, 'refuted' => 15.0, 'mixed' => 50.0, 'unverified' => 50.0];

        foreach ($claims as $claim) {
            $claimVerifications = $verifications->where('claim_id', $claim->id);
            $factChecks = $claimVerifications->filter(fn (ClaimVerification $verification): bool =>
                $verification->status === 'matched'
                && ($verification->provider === 'google_fact_check' || $verification->relation === 'external_fact_check')
                && isset($factCheckRatings[strtolower((string) $verification->normalized_rating)])
                && strtolower((string) $verification->normalized_rating) !== 'unknown'
            );
            $ratingByPublisher = [];
            $factCheckSources = [];

            foreach ($factChecks as $factCheck) {
                $url = (string) ($factCheck->fact_check_url ?? '');
                $domain = strtolower((string) ($factCheck->publisher_site ?: parse_url($url, PHP_URL_HOST) ?: ''));
                $publisherKey = $domain !== '' ? $domain : strtolower((string) ($factCheck->publisher ?: $url ?: $factCheck->id));
                $rating = strtolower((string) $factCheck->normalized_rating);
                $ratingByPublisher[$publisherKey][] = (float) $factCheckRatings[$rating];
                $factCheckSources[$publisherKey] = [
                    'publisher' => $factCheck->publisher,
                    'domain' => $domain,
                    'url' => $url,
                    'rating' => $rating,
                    'title' => $factCheck->fact_check_title,
                ];
            }

            $publisherScores = array_map(fn (array $scores): float => array_sum($scores) / count($scores), array_values($ratingByPublisher));
            $factCheckScore = $publisherScores !== [] ? array_sum($publisherScores) / count($publisherScores) : null;
            $evaluation = $evaluationByClaim->get($claim->id);
            $aiStance = strtolower((string) ($evaluation?->stance ?? 'unverified'));
            if (! isset($stanceScores[$aiStance])) {
                $aiStance = 'unverified';
            }
            $searchEvidence = $claimVerifications
                ->filter(fn (ClaimVerification $verification): bool => $verification->relation === 'search_evidence' && $verification->status === 'matched')
                ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
                ->unique('id')
                ->values();
            $linkedEvidence = $claimVerifications
                ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
                ->unique('id')
                ->values();
            $isFactual = (bool) $claim->verifiable && strtolower((string) $claim->type) !== 'opinion';
            $factChecked = $factCheckScore !== null;
            $hasModelAssessment = $evaluation !== null && $aiStance !== 'unverified' && $searchEvidence->isNotEmpty();
            $evidenceBacked = $isFactual && ($factChecked || $hasModelAssessment);
            $stance = $factChecked
                ? $this->stanceForScore($factCheckScore)
                : ($hasModelAssessment ? $aiStance : 'unverified');
            $reasoning = trim((string) ($evaluation?->reasoning ?? ''));

            if ($factChecked) {
                if ($reasoning !== '') {
                    $reasoning .= ' ';
                }
                $reasoning .= 'Rating(s) de fact-check deduplicados por publicador: '.implode(', ', array_unique(array_column(array_values($factCheckSources), 'rating'))).'.';
            } elseif (! $hasModelAssessment && $isFactual) {
                $reasoning = $reasoning !== ''
                    ? $reasoning.' No hay evidencia externa vinculada suficiente para sostener esta evaluación.'
                    : 'No se encontró una revisión de fact-check ni evidencia externa suficiente; el claim queda sin verificar.';
            } elseif (! $isFactual) {
                $reasoning = $reasoning !== '' ? $reasoning : 'Es una opinión o no se marcó como afirmación factual verificable.';
            }

            $claimScore = 50.0;
            if ($factChecked) {
                $claimScore = $factCheckScore;
            } elseif ($hasModelAssessment) {
                $modelConfidence = max(0.0, min(1.0, (float) $evaluation->confidence));
                $claimScore = 50.0 + (($stanceScores[$aiStance] - 50.0) * 0.55 * $modelConfidence);
            }

            $assessments[] = [
                'claim_id' => $claim->id,
                'claim_text' => $claim->text,
                'claim_type' => $claim->type,
                'importance' => max(0.1, (float) $claim->importance),
                'included_in_verdict' => $isFactual,
                'stance' => $isFactual ? $stance : 'unverified',
                'conclusion' => $this->claimConclusion($isFactual ? $stance : 'unverified'),
                'reasoning' => $reasoning,
                'confidence' => $factChecked
                    ? min(0.95, 0.65 + (0.1 * min(3, count($publisherScores))))
                    : ($hasModelAssessment ? min(0.65, (float) $evaluation->confidence * 0.65) : 0.0),
                'assessment_source' => $factChecked ? 'fact_check' : ($hasModelAssessment ? 'ai_review_with_search_evidence' : 'insufficient_evidence'),
                'claim_score' => round($claimScore, 2),
                'fact_checked' => $factChecked,
                'evidence_backed' => $evidenceBacked,
                'fact_check_count' => $factChecks->count(),
                'evidence_count' => $linkedEvidence->count(),
                'fact_check_rating' => $factChecked ? round($factCheckScore, 2) : null,
                'fact_check_sources' => array_values($factCheckSources),
                'evidence' => $linkedEvidence->map(fn (Evidence $evidence): array => [
                    'title' => $evidence->title,
                    'url' => $evidence->url,
                    'snippet' => $evidence->snippet,
                    'domain' => $evidence->metadata['domain'] ?? parse_url((string) $evidence->url, PHP_URL_HOST),
                ])->all(),
            ];
        }

        return $assessments;
    }

    private function stanceForScore(float $score): string
    {
        return match (true) {
            $score >= 75.0 => 'supported',
            $score <= 25.0 => 'refuted',
            default => 'mixed',
        };
    }

    private function claimConclusion(string $stance): string
    {
        return match ($stance) {
            'supported' => 'Respaldado por las revisiones/evidencias encontradas.',
            'refuted' => 'Refutado por las revisiones de fact-check disponibles.',
            'mixed' => 'Parcialmente cierto o con resultados contradictorios.',
            default => 'No hay evidencia suficiente para concluir si es cierto o falso.',
        };
    }

    private function calculateCoverage(Collection $claims, string $field, float $totalWeight): float
    {
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return min(1.0, $claims->where($field, true)->sum('importance') / $totalWeight);
    }

    private function calculateWeightedClaimScore(Collection $claims, float $totalWeight): float
    {
        if ($totalWeight <= 0.0) {
            return 50.0;
        }

        $weightedScore = $claims->sum(fn (array $claim): float => $claim['claim_score'] * $claim['importance']);

        return round($weightedScore / $totalWeight, 2);
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
        $matchedVerifications = $verifications
            ->where('status', 'matched')
            ->filter(fn (ClaimVerification $verification): bool => $verification->provider === 'google_fact_check'
                || $verification->relation === 'external_fact_check');

        if ($matchedVerifications->isEmpty()) {
            return 50.0;
        }

        $evidenceCount = $evidences->count();
        $verificationCount = $matchedVerifications
            ->filter(fn (ClaimVerification $verification): bool => $verification->evidences()
                ->exists())
            ->count();

        // Scale by number of corroborating sources found
        $score = 40.0 + (min($evidenceCount, 6) * 7.0) + (min($verificationCount, 4) * 4.5);

        return min(100.0, max(0.0, $score));
    }

    /**
     * Calculate independence score based on distinct evidence domains (0 to 100).
     *
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateIndependenceScore(array $claimEvaluations): float
    {
        $domains = collect($claimEvaluations)
            ->filter(fn (array $evaluation): bool => $evaluation['fact_checked'])
            ->flatMap(fn (array $evaluation) => $evaluation['fact_check_sources'])
            ->pluck('domain')
            ->filter()
            ->unique()
            ->count();

        if ($domains === 0) {
            return 50.0;
        }

        return match (true) {
            $domains >= 3 => 100.0,
            $domains === 2 => 80.0,
            default => 60.0,
        };
    }

    /**
     * Calculate consistency score (0 to 100).
     *
     * @param  Collection<int, Claim>  $claims
     */
    protected function calculateConsistencyScore(?AnalystSynthesisResult $analystSynthesis): float
    {
        if ($analystSynthesis === null) {
            return 50.0;
        }

        return round(max(0.0, min(1.0, $analystSynthesis->consistencyScore)) * 100.0, 2);
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

        return min(100.0, max(0.0, $score));
    }

    /**
     * Determine final verbal verdict from numeric final score.
     */
    protected function determineVerdict(float $finalScore, float $evidenceCoverage, float $factCheckCoverage): string
    {
        if ($evidenceCoverage < (float) config('scoring.minimum_evidence_coverage', 0.60)) {
            return 'inconclusive';
        }

        $thresholds = config('scoring.thresholds', [
            'credible' => 85.0,
            'probably_credible' => 65.0,
            'inconclusive' => 45.0,
            'probably_misleading' => 25.0,
        ]);

        if ($finalScore >= ($thresholds['credible'] ?? 85.0)
            && $evidenceCoverage >= (float) config('scoring.credible_coverage', 0.80)
            && $factCheckCoverage >= (float) config('scoring.credible_fact_check_coverage', 0.50)) {
            return 'credible';
        }

        if ($finalScore >= ($thresholds['probably_credible'] ?? 65.0)
            && $evidenceCoverage >= (float) config('scoring.probably_credible_coverage', 0.70)) {
            return 'probably_credible';
        }

        if ($finalScore <= (float) config('scoring.false_max_score', 15.0)
            && $factCheckCoverage >= (float) config('scoring.false_fact_check_coverage', 0.50)) {
            return 'false';
        }

        if ($finalScore <= ($thresholds['probably_misleading'] ?? 25.0)
            && $evidenceCoverage >= (float) config('scoring.probably_misleading_coverage', 0.65)) {
            return 'probably_misleading';
        }

        return 'inconclusive';
    }

    /** @return array{label: string, display: string} */
    private function determineTruthAssessment(float $score, float $coverage, Collection $claimEvaluations): array
    {
        if ($coverage < (float) config('scoring.minimum_evidence_coverage', 0.60)) {
            return ['label' => 'insufficient_evidence', 'display' => 'Evidencia insuficiente'];
        }

        $totalWeight = max(0.1, $claimEvaluations->sum('importance'));
        $supportedWeight = $claimEvaluations->where('stance', 'supported')->sum('importance') / $totalWeight;
        $refutedWeight = $claimEvaluations->where('stance', 'refuted')->sum('importance') / $totalWeight;
        $mixedWeight = $claimEvaluations->where('stance', 'mixed')->sum('importance') / $totalWeight;

        if (($supportedWeight >= 0.2 && $refutedWeight >= 0.2) || $mixedWeight >= 0.2) {
            return ['label' => 'partially_true', 'display' => 'Parcialmente cierta'];
        }

        return match (true) {
            $score >= 85.0 => ['label' => 'mostly_true', 'display' => 'Mayormente cierta'],
            $score >= 70.0 => ['label' => 'probably_true', 'display' => 'Probablemente cierta'],
            $score >= 55.0 => ['label' => 'partially_true', 'display' => 'Parcialmente cierta'],
            $score >= 35.0 => ['label' => 'inconclusive', 'display' => 'Inconclusa'],
            $score >= 20.0 => ['label' => 'probably_false', 'display' => 'Probablemente falsa'],
            default => ['label' => 'mostly_false', 'display' => 'Mayormente falsa'],
        };
    }

    private function buildArticleSummary(
        ?AnalystSynthesisResult $analystSynthesis,
        array $claimEvaluations,
        array $truthAssessment,
        float $evidenceCoverage,
    ): string {
        $summary = trim((string) ($analystSynthesis?->summary ?? ''));
        if ($summary !== '') {
            return $summary;
        }

        $factualClaims = collect($claimEvaluations)->where('included_in_verdict', true);
        $supported = $factualClaims->where('stance', 'supported')->count();
        $refuted = $factualClaims->where('stance', 'refuted')->count();
        $mixed = $factualClaims->where('stance', 'mixed')->count();
        $unverified = $factualClaims->where('stance', 'unverified')->count();

        return sprintf(
            'El análisis encontró %d afirmaciones verificables: %d respaldadas, %d refutadas, %d mixtas y %d sin verificar. La evaluación general es “%s” y la cobertura de evidencia es %.0f%%. Esta síntesis es automática; revisa las fuentes vinculadas antes de sacar conclusiones.',
            $factualClaims->count(),
            $supported,
            $refuted,
            $mixed,
            $unverified,
            strtolower($truthAssessment['display']),
            $evidenceCoverage * 100,
        );
    }

    private function buildVerdictRationale(array $truthAssessment, float $evidenceCoverage, float $factCheckCoverage): string
    {
        if ($truthAssessment['label'] === 'insufficient_evidence') {
            return sprintf(
                'La evidencia disponible cubre %.0f%% de la importancia factual analizable, por debajo del mínimo de %.0f%%. Por eso el sistema se abstiene de declarar que la noticia es verdadera o falsa.',
                $evidenceCoverage * 100,
                (float) config('scoring.minimum_evidence_coverage', 0.60) * 100,
            );
        }

        return sprintf(
            'La evaluación pondera afirmaciones individuales por importancia, separa revisiones de fact-check de resultados de búsqueda y cubre %.0f%% de las afirmaciones; %.0f%% cuenta con rating de fact-check directo.',
            $evidenceCoverage * 100,
            $factCheckCoverage * 100,
        );
    }

    /**
     * Compute confidence score between 0.0 and 1.0.
     *
     * @param  Collection<int, Claim>  $claims
     * @param  Collection<int, ClaimVerification>  $verifications
     * @param  Collection<int, Evidence>  $evidences
     */
    protected function calculateConfidenceScore(float $evidenceCoverage, float $factCheckCoverage, int $independentFactCheckers): float
    {
        $confidence = 0.10
            + (0.45 * $evidenceCoverage)
            + (0.30 * $factCheckCoverage)
            + (0.15 * min(1.0, $independentFactCheckers / 3));

        return round(min(0.95, max(0.0, $confidence)), 2);
    }
}
