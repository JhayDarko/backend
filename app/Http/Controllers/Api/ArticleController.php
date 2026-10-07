<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Services\Article\ArticleIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

final class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleIngestionService $ingestionService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        $query = Article::query()->with('source');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('author_name', 'like', "%{$search}%");
            });
        }

        if ($domain = $request->input('domain')) {
            $query->whereHas('source', function ($q) use ($domain) {
                $q->where('domain', 'like', "%{$domain}%");
            });
        }

        if ($request->query('pagination') === 'cursor' || $request->query('cursor') !== null) {
            $articles = $query
                ->orderByDesc('id')
                ->cursorPaginate($perPage)
                ->appends($request->except('cursor'));

            return ArticleResource::collection($articles);
        }

        $articles = $query->latest('id')->paginate($perPage);

        return ArticleResource::collection($articles);
    }

    public function show(Article $article): ArticleResource
    {
        $article->load(['source', 'analyses.score']);

        return new ArticleResource($article);
    }

    public function store(
        StoreArticleRequest $request
    ): JsonResponse {
        try {
            if ($request->filled('content')) {
                $article = $this->ingestionService->ingestText(
                    content: $request->validated('content'),
                    title: $request->validated('title'),
                    authorName: $request->validated('author_name'),
                );
            } else {
                $article = $this->ingestionService->ingest(
                    $request->validated('url')
                );
            }

            $article->load('source');

            return (new ArticleResource($article))
                ->response()
                ->setStatusCode(201);

        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to process the article.',
            ], 422);
        }
    }
}