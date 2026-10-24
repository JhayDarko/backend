<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnalysisQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AnalysisQuotaController extends Controller
{
    public function __invoke(Request $request, AnalysisQuotaService $quota): JsonResponse
    {
        return response()->json(['data' => $quota->summary($request->user())]);
    }
}
