<?php

return [
    /*
    | Public API plans. per_minute applies to every request; analyses_per_day
    | caps new analyses (the costly operation) per key and day.
    */
    'free' => [
        'label' => 'Gratuito',
        'per_minute' => (int) env('PLAN_FREE_PER_MINUTE', 10),
        'analyses_per_day' => (int) env('PLAN_FREE_ANALYSES_PER_DAY', 10),
    ],
    'pro' => [
        'label' => 'Pro',
        'per_minute' => (int) env('PLAN_PRO_PER_MINUTE', 60),
        'analyses_per_day' => (int) env('PLAN_PRO_ANALYSES_PER_DAY', 300),
    ],
];
