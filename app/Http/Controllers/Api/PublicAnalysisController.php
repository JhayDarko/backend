<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartAnalysis;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Resources\AnalysisResource;
use App\Models\ApiKey;
use App\Models\ApiUsage;
use App\Services\Article\ArticleIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Public API: submit a news item (URL or text) and start its analysis in one call. */
final class PublicAnalysisController extends Controller
{
    public function store(
        StoreArticleRequest $request,
        ArticleIngestionService $ingestion,
        StartAnalysis $startAnalysis,
    ): JsonResponse {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $usage = ApiUsage::query()->firstOrCreate(['api_key_id' => $key->id, 'date' => today()->toDateString()]);
        $limit = (int) $key->limits()['analyses_per_day'];

        if ($usage->analyses >= $limit) {
            return response()->json(['message' => "Alcanzaste el límite diario de {$limit} análisis de tu plan."], 429);
        }

        try {
            $article = $request->filled('content')
                ? $ingestion->ingestText($request->validated('content'), $request->validated('title'), $request->validated('author_name'), $key->user_id)
                : $ingestion->ingest($request->validated('url'), $key->user_id);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo procesar la noticia.'], 422);
        }

        [$analysis, $created] = $startAnalysis($article, $key->user_id);

        if ($created) {
            $usage->increment('analyses');
        }

        return response()->json(['data' => new AnalysisResource($analysis)], $created ? 202 : 200);
    }

    public function usage(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $today = $key->usages()->whereDate('date', today())->first();

        return response()->json(['data' => [
            'plan' => $key->plan,
            'limits' => $key->limits(),
            'today' => ['requests' => $today?->requests ?? 0, 'analyses' => $today?->analyses ?? 0, 'errors' => $today?->errors ?? 0],
        ]]);
    }
}
