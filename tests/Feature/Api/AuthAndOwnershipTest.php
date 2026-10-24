<?php

namespace Tests\Feature\Api;

use App\Models\Analysis;
use App\Models\Article;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'token', 'expires_at']);

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.com', 'password' => 'Secreta123'])
            ->assertOk();

        $this->withToken($login->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.email', 'ana@example.com');

        $this->withToken($login->json('token'))->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_refresh_rotates_the_token_and_sets_the_expiration(): void
    {
        config(['sanctum.expiration' => 10080]);
        $user = User::factory()->create();
        $oldToken = $user->createToken('web')->plainTextToken;

        $response = $this->withToken($oldToken)->postJson('/api/v1/auth/refresh')->assertOk();
        $newToken = $response->json('token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'expires_at' => Carbon::parse($response->json('expires_at'))->toDateTimeString(),
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_expired_token_returns_a_stable_error_code(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'token_expired');
    }

    public function test_expired_token_does_not_block_a_new_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'Secreta123',
        ]);
        $expiredToken = $user->createToken('web', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($expiredToken)->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'Secreta123',
        ])->assertOk()->assertJsonStructure(['token', 'expires_at']);
    }

    public function test_logout_all_revokes_every_session_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('web')->plainTextToken;
        $user->createToken('web');

        $this->withToken($currentToken)->postJson('/api/v1/auth/logout-all')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_change_revokes_other_tokens_but_keeps_current_token(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $currentToken = $user->createToken('web')->plainTextToken;
        $otherToken = $user->createToken('web')->plainTextToken;

        $this->withToken($currentToken)->putJson('/api/v1/auth/password', [
            'current_password' => 'OldPassword123',
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    public function test_sanctum_prune_command_removes_tokens_expired_for_at_least_one_day(): void
    {
        $user = User::factory()->create();
        $expiredToken = $user->createToken('expired', ['*'], now()->subDays(2));
        $recentlyExpiredToken = $user->createToken('recent', ['*'], now()->subHour());

        Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $expiredToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $recentlyExpiredToken->accessToken->id]);
    }

    public function test_authentication_is_rate_limited_by_ip_and_email(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withHeader('X-Forwarded-For', '192.0.2.'.($attempt + 1))->postJson('/api/v1/auth/login', [
                'email' => 'limit@example.com',
                'password' => 'incorrecta',
            ])->assertUnprocessable();
        }

        $this->withHeader('X-Forwarded-For', '192.0.2.11')->postJson('/api/v1/auth/login', [
            'email' => 'limit@example.com',
            'password' => 'incorrecta',
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limit_exceeded');
    }

    public function test_authentication_is_rate_limited_by_ip_even_with_different_emails(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => "limit{$attempt}@example.com",
                'password' => 'incorrecta',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'another@example.com',
            'password' => 'incorrecta',
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'rate_limit_exceeded');
    }

    public function test_expired_tokens_are_pruned_by_the_registered_schedule(): void
    {
        $schedule = app(Schedule::class);

        $this->assertStringContainsString('sanctum:prune-expired --hours=24', collect($schedule->events())->pluck('command')->implode("\n"));
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
