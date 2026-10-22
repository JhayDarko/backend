<?php

namespace Tests\Feature\Api;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthAndOwnershipTest extends TestCase
{
    public function test_user_can_register_login_and_logout(): void
    {
        $payload = ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'Secreta123', 'password_confirmation' => 'Secreta123'];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'Secreta123'])
            ->assertOk();

        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.email', 'ana@example.com');

        $this->withToken($login->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_wrong_credentials(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'incorrecta'])
            ->assertStatus(422);
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', ['name' => 'Ana', 'email' => 'a@example.com', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/analyses')->assertUnauthorized();
        $this->postJson('/api/v1/articles', ['content' => str_repeat('texto ', 10)])->assertUnauthorized();
    }

    public function test_users_cannot_access_other_users_analyses_or_articles(): void
    {
        $owner = User::factory()->create();
        $article = Article::create(['created_by' => $owner->id, 'title' => 'Privada', 'content' => 'Contenido privado de prueba.']);
        $analysis = Analysis::create(['article_id' => $article->id, 'user_id' => $owner->id, 'status' => 'completed', 'algorithm_version' => '1.2.0']);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/analyses/{$analysis->id}")->assertForbidden();
        $this->getJson("/api/v1/analyses/{$analysis->id}/status")->assertForbidden();
        $this->postJson("/api/v1/analyses/{$analysis->id}/retry")->assertForbidden();
        $this->getJson("/api/v1/articles/{$article->id}")->assertForbidden();
        $this->postJson("/api/v1/articles/{$article->id}/analyze")->assertForbidden();
        $this->getJson('/api/v1/analyses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_each_user_gets_their_own_analysis_of_the_same_article(): void
    {
        Queue::fake();
        $first = User::factory()->create();
        $article = Article::create(['created_by' => $first->id, 'title' => 'Compartida', 'content' => 'Contenido compartido de prueba.']);

        Sanctum::actingAs($first);
        $a = $this->postJson("/api/v1/articles/{$article->id}/analyze")->assertAccepted()->json('data.id');

        Sanctum::actingAs($first);
        $this->postJson("/api/v1/articles/{$article->id}/analyze")->assertOk()->assertJsonPath('data.id', $a);
        $this->assertSame(1, Analysis::count());
    }
}
