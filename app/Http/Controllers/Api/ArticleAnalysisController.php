<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartAnalysis;
use App\Exceptions\AnalysisQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeArticleRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\Article;
use App\Services\AnalysisQuotaService;
use Illuminate\Http\JsonResponse;

final class ArticleAnalysisController extends Controller
{
    public function store(
        AnalyzeArticleRequest $request,
        Article $article,
        StartAnalysis $startAnalysis,
        AnalysisQuotaService $quota,
    ): JsonResponse {
        $this->authorize('view', $article);

        try {
            [$analysis, $created] = $quota->start($request->user(), $article, $startAnalysis);
        } catch (AnalysisQuotaExceededException $exception) {
            return $exception->response();
        }

        return response()->json(['data' => new AnalysisResource($analysis)], $created ? 202 : 200);
    }
}
