<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\Evidence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class AccountDeletionService
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $articles = Article::query()
                ->where('created_by', $user->id)
                ->get(['id', 'metadata']);
            $articleIds = $articles->modelKeys();

            $analysisQuery = Analysis::query()->where('user_id', $user->id);
            if ($articleIds !== []) {
                $analysisQuery->orWhereIn('article_id', $articleIds);
            }
            $analysisIds = $analysisQuery->pluck('id');

            $evidenceIds = $analysisIds->isEmpty()
                ? collect()
                : DB::table('verification_evidence')
                    ->join('claim_verifications', 'verification_evidence.claim_verification_id', '=', 'claim_verifications.id')
                    ->join('claims', 'claim_verifications.claim_id', '=', 'claims.id')
                    ->whereIn('claims.analysis_id', $analysisIds)
                    ->distinct()
                    ->pluck('verification_evidence.evidence_id');

            $imagePaths = $articles
                ->map(fn (Article $article): mixed => data_get($article->metadata, 'ocr_image_path'))
                ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
                ->unique()
                ->values();

            $disk = Storage::disk((string) config('filesystems.default'));
            foreach ($imagePaths as $path) {
                if (! str_starts_with($path, 'ocr-images/') || str_contains($path, '..')) {
                    throw new RuntimeException('An account-owned image path is invalid.');
                }

                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new RuntimeException('An account-owned image could not be deleted.');
                }
            }

            $user->analyses()->delete();
            if ($articleIds !== []) {
                Article::query()->whereIn('id', $articleIds)->delete();
            }

            $user->tokens()->delete();
            $user->delete();

            if ($evidenceIds->isNotEmpty()) {
                Evidence::query()
                    ->whereIn('id', $evidenceIds)
                    ->whereDoesntHave('claimVerifications')
                    ->delete();
            }
        }, 3);
    }
}
