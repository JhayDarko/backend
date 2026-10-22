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
use App\Services\FactCheck\FactCheckRatingNormalizer;
use App\Services\Search\SourceDomainPolicy;
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
        $claimEvaluations = $this->applyClaimWeights($claimEvaluations);
        $factualClaims = collect($claimEvaluations)->where('included_in_verdict', true);
        $totalClaimWeight = $factualClaims->sum('weight');
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
                'central_claim_id' => collect($claimEvaluations)->firstWhere('is_central', true)['claim_id'] ?? null,
                'unverifiable_claims_count' => collect($claimEvaluations)->where('included_in_verdict', false)->count(),
                'unresolved_claims_count' => $factualClaims->where('evidence_backed', false)->count(),
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
            $ratingByVerification = [];
            $factChecks = $claimVerifications->filter(function (ClaimVerification $verification) use ($factCheckRatings, &$ratingByVerification): bool {
                if ($verification->status !== 'matched'
                    || ($verification->provider !== 'google_fact_check' && $verification->relation !== 'external_fact_check')) {
                    return false;
                }

                $rating = strtolower((string) $verification->normalized_rating);
                if ($rating === '' || $rating === 'unknown') {
                    $rating = FactCheckRatingNormalizer::normalize((string) $verification->raw_rating)['normalized_rating'];
                }

                if ($rating === 'unknown' || ! isset($factCheckRatings[$rating])) {
                    return false;
                }

                $ratingByVerification[$verification->id] = $rating;

                return true;
            });
            $ratingByPublisher = [];
            $factCheckSources = [];

            foreach ($factChecks as $factCheck) {
                $url = (string) ($factCheck->fact_check_url ?? '');
                $domain = strtolower((string) ($factCheck->publisher_site ?: parse_url($url, PHP_URL_HOST) ?: ''));
                $publisherKey = $domain !== '' ? $domain : strtolower((string) ($factCheck->publisher ?: $url ?: $factCheck->id));
                $rating = $ratingByVerification[$factCheck->id];
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
            $domainPolicy = app(SourceDomainPolicy::class);
            $searchEvidence = $claimVerifications
                ->filter(fn (ClaimVerification $verification): bool => $verification->relation === 'search_evidence' && $verification->status === 'matched')
                ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
                ->reject(fn ($evidence): bool => $domainPolicy->isBlocked((string) $evidence->url))
                ->unique('id')
                ->values();
            $linkedEvidence = $claimVerifications
                ->flatMap(fn (ClaimVerification $verification) => $verification->evidences)
                ->reject(fn ($evidence): bool => $domainPolicy->isBlocked((string) $evidence->url))
                ->unique('id')
                ->values();
            $isFactual = (bool) $claim->verifiable && ! in_array(strtolower((string) $claim->type), ['opinion', 'prediction'], true);
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
                $reasoning = $reasoning !== '' ? $reasoning : match (strtolower((string) $claim->type)) {
                    'opinion' => 'Es una opinión: no se verifica como hecho y no pesa en el veredicto.',
                    'prediction' => 'Es una predicción sobre el futuro: no se puede comprobar todavía y no pesa en el veredicto.',
                    default => 'No es comprobable con fuentes externas, por lo que no pesa en el veredicto.',
                };
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

    /**
     * The main claim (highest importance) weighs more; secondary claims without evidence weigh less
     * so minor unverifiable details do not drag the verdict. Non-factual claims are labelled by reason.
     *
     * @param  array<int, array<string, mixed>>  $assessments
     * @return array<int, array<string, mixed>>
     */
    private function applyClaimWeights(array $assessments): array
    {
        $ranked = collect($assessments)->where('included_in_verdict', true)->sortByDesc('importance')->values();
        $margin = (float) config('scoring.central_claim_margin', 0.1);
        $centralId = $ranked->isNotEmpty()
            && ($ranked->count() === 1 || $ranked[0]['importance'] - $ranked[1]['importance'] >= $margin)
            ? $ranked[0]['claim_id']
            : null;
        $centralWeight = max(1.0, (float) config('scoring.central_claim_weight', 2.0));
        $unresolvedWeight = max(0.0, min(1.0, (float) config('scoring.unverified_secondary_weight', 0.4)));

        return array_map(function (array $assessment) use ($centralId, $centralWeight, $unresolvedWeight): array {
            $isCentral = $assessment['included_in_verdict'] && $assessment['claim_id'] === $centralId;
            $weight = $assessment['importance'];
            if ($isCentral) {
                $weight *= $centralWeight;
            } elseif ($assessment['included_in_verdict'] && ! $assessment['evidence_backed']) {
                $weight *= $unresolvedWeight;
            }

            $reason = match (true) {
                $assessment['included_in_verdict'] && $assessment['evidence_backed'] => null,
                $assessment['included_in_verdict'] => 'no_evidence_found',
                strtolower((string) $assessment['claim_type']) === 'opinion' => 'opinion',
                strtolower((string) $assessment['claim_type']) === 'prediction' => 'prediction',
                default => 'not_checkable',
            };

            return $assessment + [
                'is_central' => $isCentral,
                'weight' => round($weight, 4),
                'unverifiable_reason' => $reason,
            ];
        }, $assessments);
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

        return min(1.0, $claims->where($field, true)->sum('weight') / $totalWeight);
    }

    private function calculateWeightedClaimScore(Collection $claims, float $totalWeight): float
    {
        if ($totalWeight <= 0.0) {
            return 50.0;
        }

        $weightedScore = $claims->sum(fn (array $claim): float => $claim['claim_score'] * $claim['weight']);

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

        // A classifier near 50% says little: stay neutral instead of rewarding or punishing.
        if ($prob < 0.6) {
            return 50.0;
        }

        if ($label === 'REAL') {
            return min(100.0, 50.0 + (($prob - 0.5) * 100.0));
        }

        // Style alone (sensational or emotional wording) must not condemn a story: floor at 20.
        if ($label === 'FAKE') {
            return max(20.0, 50.0 - (($prob - 0.5) * 100.0));
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

        $totalWeight = max(0.1, $claimEvaluations->sum('weight'));
        $supportedWeight = $claimEvaluations->where('stance', 'supported')->sum('weight') / $totalWeight;
        $refutedWeight = $claimEvaluations->where('stance', 'refuted')->sum('weight') / $totalWeight;
        $mixedWeight = $claimEvaluations->where('stance', 'mixed')->sum('weight') / $totalWeight;

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
            'De los %d hechos principales que se pudieron comprobar, %d están respaldados, %d fueron desmentidos, %d son parcialmente ciertos o contradictorios y %d no se pudieron confirmar. En conjunto, la noticia resulta %s. La falta de confirmación no implica que algo sea falso: conviene consultar las fuentes enlazadas.',
            $factualClaims->count(),
            $supported,
            $refuted,
            $mixed,
            $unverified,
            strtolower($truthAssessment['display']),
        );
    }

    private function buildVerdictRationale(array $truthAssessment, float $evidenceCoverage, float $factCheckCoverage): string
    {
        if ($truthAssessment['label'] === 'insufficient_evidence') {
            return sprintf(
                'Solo se pudo contrastar con fuentes externas cerca del %.0f%% de lo que afirma la noticia, menos del %.0f%% mínimo necesario. Por eso no se afirma que sea verdadera ni falsa.',
                $evidenceCoverage * 100,
                (float) config('scoring.minimum_evidence_coverage', 0.60) * 100,
            );
        }

        return sprintf(
            'Se contrastó con fuentes externas cerca del %.0f%% de lo que afirma la noticia, dándole más peso a su idea principal; en %.0f%% hubo una verificación directa de verificadores de datos.',
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
