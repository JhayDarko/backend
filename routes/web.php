<?php

use Illuminate\Support\Facades\Route;

// The Angular SPA runs separately in development; this host exposes the API.
Route::get('/', fn () => response()->json([
    'name' => 'FactNode API',
    'docs' => url('/api-docs/openapi.yaml'),
    'health' => url('/up'),
]));
