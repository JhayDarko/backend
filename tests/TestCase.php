<?php

namespace Tests;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected ?User $user = null;

    /** Authenticates a user and makes models created by the test belong to it. */
    protected function actingAsUser(): User
    {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        Article::creating(fn (Article $article) => $article->created_by ??= $this->user->id);
        Analysis::creating(fn (Analysis $analysis) => $analysis->user_id ??= $this->user->id);

        return $this->user;
    }
}
