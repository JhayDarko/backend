<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    private function issueKey(?User $user = null, string $plan = 'free'): array
    {
        [$key, $plain] = ApiKey::issue($user ?? User::factory()->create(), 'Integración', $plan);

        return [$key, $plain];
    }

    public function test_user_manages_api_keys_and_plaintext_is_shown_once(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $created = $this->postJson('/api/v1/api-keys', ['name' => 'Mi app'])->assertCreated();
        $this->assertStringStartsWith('fn_live_', $created->json('key'));
        $this->assertDatabaseMissing('api_keys', ['key_hash' => $created->json('key')]);

        $this->getJson('/api/v1/api-keys')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.key');

        $this->deleteJson('/api/v1/api-keys/'.$created->json('data.id'))->assertOk();
        $this->getJson('/api/v1/api-keys')->assertJsonCount(0, 'data');
    }

    public function test_requests_without_a_valid_key_are_rejected(): void
    {
        $this->postJson('/api/public/v1/analyses', ['content' => str_repeat('texto de prueba ', 5)])->assertUnauthorized();
        $this->withHeader('X-API-Key', 'fn_live_falsa')->getJson('/api/public/v1/usage')->assertUnauthorized();
    }

    public function test_revoked_keys_stop_working(): void
    {
        [$key, $plain] = $this->issueKey();
        $key->update(['revoked_at' => now()]);

        $this->withHeader('X-API-Key', $plain)->getJson('/api/public/v1/usage')->assertUnauthorized();
    }

    public function test_key_can_start_and_read_an_analysis_and_usage_is_recorded(): void
    {
        Queue::fake();
        [$key, $plain] = $this->issueKey();

        $created = $this->withHeader('X-API-Key', $plain)
            ->postJson('/api/public/v1/analyses', ['content' => 'La NASA confirmó que la misión DART modificó la órbita.'])
            ->assertAccepted()
            ->assertHeader('X-RateLimit-Limit', '10');

        $id = $created->json('data.id');
        $this->withHeader('X-API-Key', $plain)->getJson("/api/public/v1/analyses/{$id}/status")->assertOk();

        $this->withHeader('X-API-Key', $plain)->getJson('/api/public/v1/usage')
            ->assertOk()->assertJsonPath('data.today.analyses', 1)->assertJsonPath('data.today.requests', 2);
        $this->assertDatabaseHas('analyses', ['id' => $id, 'user_id' => $key->user_id]);
    }

    public function test_a_key_cannot_read_analyses_of_other_users(): void
    {
        Queue::fake();
        [, $ownerKey] = $this->issueKey();
        [, $otherKey] = $this->issueKey();

        $id = $this->withHeader('X-API-Key', $ownerKey)
            ->postJson('/api/public/v1/analyses', ['content' => 'Texto privado suficientemente largo para analizar.'])->json('data.id');

        $this->withHeader('X-API-Key', $otherKey)->getJson("/api/public/v1/analyses/{$id}")->assertForbidden();
    }

    public function test_free_plan_daily_analysis_quota_is_enforced(): void
    {
        Queue::fake();
        config(['plans.free.analyses_per_day' => 1, 'plans.free.per_minute' => 100]);
        [, $plain] = $this->issueKey();

        $this->withHeader('X-API-Key', $plain)->postJson('/api/public/v1/analyses', ['content' => 'Primera noticia con texto suficiente para analizar.'])->assertAccepted();
        $this->withHeader('X-API-Key', $plain)->postJson('/api/public/v1/analyses', ['content' => 'Segunda noticia con texto suficiente para analizar.'])->assertStatus(429);
    }

    public function test_per_minute_limit_depends_on_the_plan(): void
    {
        config(['plans.free.per_minute' => 2]);
        [$key, $plain] = $this->issueKey();
        RateLimiter::clear('api-key:'.$key->id);

        $this->withHeader('X-API-Key', $plain)->getJson('/api/public/v1/usage')->assertOk();
        $this->withHeader('X-API-Key', $plain)->getJson('/api/public/v1/usage')->assertOk();
        $this->withHeader('X-API-Key', $plain)->getJson('/api/public/v1/usage')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_plan_command_upgrades_a_key(): void
    {
        [$key] = $this->issueKey();

        $this->artisan('apikeys:plan', ['id' => $key->id, 'plan' => 'pro'])->assertSuccessful();
        $this->assertSame('pro', $key->fresh()->plan);
        $this->artisan('apikeys:plan', ['id' => $key->id, 'plan' => 'otro'])->assertFailed();
    }
}
