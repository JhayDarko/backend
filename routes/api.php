<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\AnalysisQuotaController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ArticleAnalysisController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\PublicAnalysisController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/auth/quota', AnalysisQuotaController::class);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth-refresh');
        Route::put('/auth/password', [AuthController::class, 'changePassword']);
        Route::post('/auth/google/link', [AuthController::class, 'linkGoogle'])->middleware('throttle:10,1');
        Route::delete('/auth/google', [AuthController::class, 'unlinkGoogle']);
        Route::delete('/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1');

        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

        Route::get('/sources', [SourceController::class, 'index'])->middleware('throttle:60,1');
        Route::get('/sources/{source}/profile', [SourceController::class, 'show'])->middleware('throttle:60,1');

        Route::get('/articles', [ArticleController::class, 'index'])
            ->middleware('throttle:60,1');
        Route::post('/articles', [ArticleController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::post('/articles/ocr', [OcrController::class, 'store'])
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
        Route::get('/analyses/{analysis}/report/pdf', [ReportController::class, 'pdf'])
            ->middleware('throttle:20,1');
        Route::get('/analyses/{analysis}/report/json', [ReportController::class, 'json'])
            ->middleware('throttle:30,1');
    });
});

// Public API (API key + plan limits): /api/public/v1/*
Route::prefix('public/v1')->middleware('api.key')->group(function () {
    Route::post('/analyses', [PublicAnalysisController::class, 'store']);
    Route::get('/analyses/{analysis}', [AnalysisController::class, 'show']);
    Route::get('/analyses/{analysis}/status', [AnalysisController::class, 'status']);
    Route::get('/analyses/{analysis}/report/json', [ReportController::class, 'json']);
    Route::get('/analyses/{analysis}/report/pdf', [ReportController::class, 'pdf']);
    Route::get('/usage', [PublicAnalysisController::class, 'usage']);
});
