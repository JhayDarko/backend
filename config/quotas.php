<?php

return [
    'analyses_per_day' => (int) env('ANALYSES_PER_DAY', 10),
    'concurrent_analyses_per_user' => (int) env('ANALYSES_CONCURRENT_PER_USER', 2),
];
