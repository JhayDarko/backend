<?php

namespace Tests\Feature\Api;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Exceptions\GoogleVerificationUnavailableException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-google-client-id']);
    }

    public function test_google_sign_in_creates_a_verified_passwordless_user_and_token(): void
    {
        $this->useGoogleClaims($this->validClaims());

        $response = $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'auth_methods'], 'token', 'expires_at'])
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonPath('data.auth_methods', ['google']);

        $user = User::query()->where('email', 'ana@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => 'ana@example.com',
        ]);
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_existing_google_identity_logs_into_its_linked_user(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => 'ana@example.com',
        ]);
        $this->useGoogleClaims($this->validClaims());

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_sign_in_does_not_automatically_link_an_existing_local_account(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $this->useGoogleClaims($this->validClaims());

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertConflict()
            ->assertJsonPath('code', 'account_exists');

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_google_token_is_rejected(): void
    {
        $this->useGoogleClaims(null);

        $this->postJson('/api/v1/auth/google', ['credential' => 'not-a-valid-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_token_invalid');
    }

    public function test_google_token_with_wrong_audience_is_rejected(): void
    {
        $this->useGoogleClaims($this->validClaims(['aud' => 'another-client-id']));

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_token_invalid');
    }

    public function test_expired_google_token_is_rejected(): void
    {
        $this->useGoogleClaims($this->validClaims(['exp' => Carbon::now()->subMinute()->timestamp]));

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_token_invalid');
    }

    public function test_unverified_google_email_is_rejected(): void
    {
        $this->useGoogleClaims($this->validClaims(['email_verified' => false]));

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_email_not_verified');
    }

    public function test_google_token_with_wrong_issuer_is_rejected(): void
    {
        $this->useGoogleClaims($this->validClaims(['iss' => 'https://attacker.example']));

        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_token_invalid');
    }

    public function test_google_sign_in_reports_configuration_and_verification_service_errors(): void
    {
        config(['services.google.client_id' => '']);
        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'google_auth_unavailable');

        config(['services.google.client_id' => 'test-google-client-id']);
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new FakeGoogleIdTokenVerifier(
            failure: new GoogleVerificationUnavailableException,
        ));
        $this->postJson('/api/v1/auth/google', ['credential' => 'fake-id-token'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'google_auth_unavailable');
    }

    public function test_authenticated_user_can_link_google_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->useGoogleClaims($this->validClaims());

        $this->postJson('/api/v1/auth/google/link', ['credential' => 'fake-id-token'])
            ->assertOk()
            ->assertJsonPath('data.auth_methods', ['password', 'google']);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider_user_id' => 'google-subject-1',
        ]);
    }

    public function test_google_account_already_linked_to_another_user_cannot_be_linked(): void
    {
        $owner = User::factory()->create();
        $owner->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => 'ana@example.com',
        ]);
        Sanctum::actingAs(User::factory()->create());
        $this->useGoogleClaims($this->validClaims());

        $this->postJson('/api/v1/auth/google/link', ['credential' => 'fake-id-token'])
            ->assertConflict()
            ->assertJsonPath('code', 'google_account_already_linked');
    }

    public function test_google_cannot_be_unlinked_when_it_is_the_only_sign_in_method(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => null]);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => 'ana@example.com',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/auth/google')
            ->assertConflict()
            ->assertJsonPath('code', 'last_auth_method');

        $this->assertDatabaseCount('social_accounts', 1);
    }

    public function test_user_with_password_can_unlink_google(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => 'ana@example.com',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/auth/google')
            ->assertOk()
            ->assertJsonPath('data.auth_methods', ['password']);

        $this->assertDatabaseCount('social_accounts', 0);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validClaims(array $overrides = []): array
    {
        return array_replace([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'exp' => Carbon::now()->addHour()->timestamp,
            'email_verified' => true,
            'sub' => 'google-subject-1',
            'email' => 'Ana@Example.com',
            'name' => 'Ana Example',
        ], $overrides);
    }

    /** @param array<string, mixed>|null $claims */
    private function useGoogleClaims(?array $claims): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new FakeGoogleIdTokenVerifier($claims));
    }
}

final class FakeGoogleIdTokenVerifier implements GoogleIdTokenVerifierInterface
{
    public function __construct(
        private readonly ?array $claims = null,
        private readonly ?GoogleVerificationUnavailableException $failure = null,
    ) {}

    public function verify(string $idToken): ?array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->claims;
    }
}
