<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartAnalysis;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeArticleRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;

final class ArticleAnalysisController extends Controller
{
    public function store(
        AnalyzeArticleRequest $request,
        Article $article,
        StartAnalysis $startAnalysis,
    ): JsonResponse {
        $this->authorize('view', $article);

        [$analysis, $created] = $startAnalysis($article, $request->user()->id);

        return response()->json(['data' => new AnalysisResource($analysis)], $created ? 202 : 200);
    }
}
