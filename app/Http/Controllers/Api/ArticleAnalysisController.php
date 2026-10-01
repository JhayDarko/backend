<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyzeArticleRequest;
use App\Http\Resources\AnalysisResource;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

final class ArticleAnalysisController extends Controller
{
    public function store(
        AnalyzeArticleRequest $request,
        Article $article
    ): JsonResponse {

        $existingAnalysis = Analysis::where('article_id', $article->id)->first();

        if ($existingAnalysis) {
            return response()->json([
                'data' => new AnalysisResource($existingAnalysis),
            ]);
        }

        try {
            $analysis = Analysis::create([
                'article_id' => $article->id,
                'user_id' => null,
                'status' => 'pending',
                'algorithm_version' => '1.0.0',
            ]);
        } catch (QueryException $exception) {
            if (!$this->isDuplicateAnalysisException($exception)) {
                throw $exception;
            }

            $analysis = Analysis::where('article_id', $article->id)->firstOrFail();

            return response()->json([
                'data' => new AnalysisResource($analysis),
            ]);
        }

        AnalyzeArticleJob::dispatch($analysis->id)->onConnection('redis');

        return response()->json([
            'data' => new AnalysisResource($analysis),
        ], 202);
    }

    private function isDuplicateAnalysisException(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}