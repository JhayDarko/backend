<?php

return [
    'algorithm_version' => env('SCORING_ALGORITHM_VERSION', '1.0.0'),

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
        'evidence' => (float) env('SCORING_WEIGHT_EVIDENCE', 0.22),
        'language' => (float) env('SCORING_WEIGHT_LANGUAGE', 0.18),
        'source_quality' => (float) env('SCORING_WEIGHT_SOURCE', 0.15),
        'corroboration' => (float) env('SCORING_WEIGHT_CORROBORATION', 0.15),
        'independence' => (float) env('SCORING_WEIGHT_INDEPENDENCE', 0.10),
        'consistency' => (float) env('SCORING_WEIGHT_CONSISTENCY', 0.12),
        'transparency' => (float) env('SCORING_WEIGHT_TRANSPARENCY', 0.08),
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
