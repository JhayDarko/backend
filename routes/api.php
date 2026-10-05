<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\ArticleAnalysisController;
use App\Http\Controllers\Api\AnalysisController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/articles', [ArticleController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::post('/articles', [ArticleController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('/articles/{article}', [ArticleController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::post('/articles/{article}/analyze', [ArticleAnalysisController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::get('/analyses', [AnalysisController::class, 'index'])
        ->middleware('throttle:60,1');
    Route::get('/analyses/{analysis}', [AnalysisController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::get('/analyses/{analysis}/status', [AnalysisController::class, 'status'])
        ->middleware('throttle:120,1');
    Route::post('/analyses/{analysis}/retry', [AnalysisController::class, 'retry'])
        ->middleware('throttle:20,1');
});