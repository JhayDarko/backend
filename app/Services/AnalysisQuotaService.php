<?php

namespace App\Services;

use App\Actions\StartAnalysis;
use App\Exceptions\AnalysisAlreadyActiveException;
use App\Exceptions\AnalysisQuotaExceededException;
use App\Jobs\AnalyzeArticleJob;
use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use App\Models\UserAnalysisUsage;
use Illuminate\Support\Facades\DB;

final class AnalysisQuotaService
{
    /** @return array{used: int, limit: int, remaining: int, active: int, concurrent_limit: int, resets_at: string} */
    public function summary(User $user): array
    {
        $usage = $this->todayUsage($user->id);
        $limit = $this->dailyLimit();
        $active = $this->activeCount($user->id);

        return [
            'used' => $usage->analyses,
            'limit' => $limit,
            'remaining' => max(0, $limit - $usage->analyses),
            'active' => $active,
            'concurrent_limit' => $this->concurrentLimit(),
            'resets_at' => $this->resetsAt(),
        ];
    }

    /** @return array{0: Analysis, 1: bool} */
    public function start(User $user, Article $article, StartAnalysis $startAnalysis): array
    {
        $result = DB::transaction(function () use ($user, $article, $startAnalysis): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = Analysis::query()
                ->where('article_id', $article->id)
                ->where('user_id', $user->id)
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $usage = $this->todayUsage($user->id, lock: true);
            $this->assertCanStart($usage->analyses, $this->activeCount($user->id));
            [$analysis, $created] = $startAnalysis($article, $user->id);

            if ($created) {
                $usage->increment('analyses');
            }

            return [$analysis, $created];
        }, 3);

        if ($result[1]) {
            AnalyzeArticleJob::dispatch($result[0]->id)->onConnection('redis');
        }

        return $result;
    }

    public function retry(User $user, Analysis $analysis): Analysis
    {
        $analysis = DB::transaction(function () use ($user, $analysis): Analysis {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = Analysis::query()->whereKey($analysis->id)->lockForUpdate()->firstOrFail();

            if (in_array($current->status, ['pending', 'processing'], true)) {
                throw new AnalysisAlreadyActiveException;
            }

            $usage = $this->todayUsage($user->id, lock: true);
            $this->assertCanStart($usage->analyses, $this->activeCount($user->id));
            $current->update([
                'status' => 'pending',
                'error' => null,
                'completed_at' => null,
                'started_at' => null,
            ]);
            $usage->increment('analyses');

            return $current;
        }, 3);

        AnalyzeArticleJob::dispatch($analysis->id)->onConnection('redis');

        $analysis->refresh();
        $analysis->load(['article.source']);

        return $analysis;
    }

    private function assertCanStart(int $used, int $active): void
    {
        if ($used >= $this->dailyLimit()) {
            throw new AnalysisQuotaExceededException(
                'daily',
                $this->dailyLimit(),
                $active,
                $this->concurrentLimit(),
                $this->resetsAt(),
            );
        }

        if ($active >= $this->concurrentLimit()) {
            throw new AnalysisQuotaExceededException(
                'concurrent',
                $this->dailyLimit(),
                $active,
                $this->concurrentLimit(),
                $this->resetsAt(),
            );
        }
    }

    private function todayUsage(int $userId, bool $lock = false): UserAnalysisUsage
    {
        $usage = UserAnalysisUsage::query()->firstOrCreate([
            'user_id' => $userId,
            'date' => today()->toDateString(),
        ]);

        return $lock
            ? UserAnalysisUsage::query()->whereKey($usage->id)->lockForUpdate()->firstOrFail()
            : $usage;
    }

    private function activeCount(int $userId): int
    {
        return Analysis::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'processing'])
            ->count();
    }

    private function dailyLimit(): int
    {
        return max(0, (int) config('quotas.analyses_per_day', 10));
    }

    private function concurrentLimit(): int
    {
        return max(1, (int) config('quotas.concurrent_analyses_per_user', 2));
    }

    private function resetsAt(): string
    {
        return now()->startOfDay()->addDay()->toIso8601String();
    }
}
