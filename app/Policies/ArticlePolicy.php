<?php

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function view(User $user, Article $article): bool
    {
        return Article::query()->visibleTo($user)->whereKey($article->getKey())->exists();
    }

    public function updateTitle(User $user, Article $article): bool
    {
        return $article->created_by === $user->id;
    }
}
