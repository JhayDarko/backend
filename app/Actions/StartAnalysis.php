<?php

namespace App\Actions;

use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use Illuminate\Database\QueryException;

/** Creates (or reuses) the user's analysis of an article and queues the pipeline. */
final class StartAnalysis
{
    /** @return array{0: Analysis, 1: bool} the analysis and whether it was newly created */
    public function __invoke(Article $article, int $userId): array
    {
        $existing = Analysis::where('article_id', $article->id)->where('user_id', $userId)->first();
        if ($existing) {
            return [$existing, false];
        }

        try {
            $analysis = Analysis::create([
                'article_id' => $article->id,
                'user_id' => $userId,
                'status' => 'pending',
                'algorithm_version' => '1.0.0',
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            return [Analysis::where('article_id', $article->id)->where('user_id', $userId)->firstOrFail(), false];
        }

        AnalyzeArticleJob::dispatch($analysis->id)->onConnection('redis');

        return [$analysis, true];
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true)
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
