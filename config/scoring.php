<?php

return [
    'algorithm_version' => env('SCORING_ALGORITHM_VERSION', '1.2.0'),

    /*
    |--------------------------------------------------------------------------
    | Component Weights
    |--------------------------------------------------------------------------
    |
    | Default weights for combining independent signals.
    | Must sum to 1.0.
    |
    */
    'weights' => [
        'evidence' => (float) env('SCORING_WEIGHT_EVIDENCE', 0.72),
        'language' => (float) env('SCORING_WEIGHT_LANGUAGE', 0.03),
        'source_quality' => (float) env('SCORING_WEIGHT_SOURCE', 0.05),
        'corroboration' => (float) env('SCORING_WEIGHT_CORROBORATION', 0.12),
        'independence' => (float) env('SCORING_WEIGHT_INDEPENDENCE', 0.03),
        'consistency' => (float) env('SCORING_WEIGHT_CONSISTENCY', 0.05),
        'transparency' => (float) env('SCORING_WEIGHT_TRANSPARENCY', 0.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Verdict Thresholds
    |--------------------------------------------------------------------------
    |
    | Final score ranges (0 to 100) mapped to human-readable verdicts.
    |
    */
    'thresholds' => [
        'credible' => 85.0,
        'probably_credible' => 65.0,
        'inconclusive' => 45.0,
        'probably_misleading' => 25.0,
    ],

    'minimum_evidence_coverage' => (float) env('SCORING_MIN_EVIDENCE_COVERAGE', 0.60),
    'credible_coverage' => (float) env('SCORING_CREDIBLE_COVERAGE', 0.80),
    'credible_fact_check_coverage' => (float) env('SCORING_CREDIBLE_FACT_CHECK_COVERAGE', 0.50),
    'probably_credible_coverage' => (float) env('SCORING_PROBABLY_CREDIBLE_COVERAGE', 0.70),
    'probably_misleading_coverage' => (float) env('SCORING_PROBABLY_MISLEADING_COVERAGE', 0.65),
    'false_fact_check_coverage' => (float) env('SCORING_FALSE_FACT_CHECK_COVERAGE', 0.50),
    'false_max_score' => (float) env('SCORING_FALSE_MAX_SCORE', 25.0),

    /*
    |--------------------------------------------------------------------------
    | Fact-Check Rating Normalization Weights (0-100)
    |--------------------------------------------------------------------------
    */
    'fact_check_rating_scores' => [
        'true' => 100.0,
        'mostly_true' => 80.0,
        'mixed' => 50.0,
        'mostly_false' => 20.0,
        'false' => 0.0,
        'unknown' => 50.0,
    ],
];
