<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnalysisResource;
use App\Http\Resources\AnalysisStatusResource;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AnalysisController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $query = Analysis::query()
            ->with(['article.source', 'score', 'predictions']);

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($verdict = $request->input('final_verdict')) {
            $query->where('final_verdict', $verdict);
        }

        if ($articleId = $request->input('article_id')) {
            $query->where('article_id', $articleId);
        }

        $analyses = $query->latest('id')->paginate($perPage);

        return AnalysisResource::collection($analyses);
    }

    public function show(Analysis $analysis): AnalysisResource
    {
        $analysis->load([
            'article.source',
            'score',
            'predictions',
            'claims.claimVerifications.evidences',
        ]);

        return new AnalysisResource($analysis);
    }

    public function status(Analysis $analysis): AnalysisStatusResource
    {
        return new AnalysisStatusResource($analysis);
    }

    public function retry(Analysis $analysis): JsonResponse
    {
        if ($analysis->status === 'processing') {
            return response()->json([
                'message' => 'Analysis is currently processing and cannot be restarted.',
            ], 409);
        }

        $analysis->update([
            'status' => 'pending',
            'error' => null,
            'completed_at' => null,
            'started_at' => null,
        ]);

        AnalyzeArticleJob::dispatch($analysis->id);

        return response()->json([
            'message' => 'Analysis has been re-queued for processing.',
            'data' => new AnalysisResource($analysis->fresh(['article.source'])),
        ], 202);
    }
}