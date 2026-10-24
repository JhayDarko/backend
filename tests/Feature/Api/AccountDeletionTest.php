<?php

namespace Tests\Feature\Api;

use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Exceptions\GoogleVerificationUnavailableException;
use App\Models\Analysis;
use App\Models\ApiKey;
use App\Models\Article;
use App\Models\Claim;
use App\Models\ClaimVerification;
use App\Models\Evidence;
use App\Models\Score;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    public function test_account_deletion_requires_reauthentication(): void
    {
        $user = User::factory()->create(['password' => 'CurrentPass123']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_reauthentication');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_account_deletion_rejects_an_incorrect_password(): void
    {
        $user = User::factory()->create(['password' => 'CurrentPass123']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/account', ['password' => 'wrong'])
            ->assertForbidden()
            ->assertJsonPath('code', 'reauthentication_failed');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_password_reauthentication_deletes_account_data_and_owned_capture(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $user = User::factory()->create(['password' => 'CurrentPass123']);
        $token = $user->createToken('web')->plainTextToken;
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => $user->email,
        ]);
        [$apiKey] = ApiKey::issue($user, 'test key');
        $apiKey->usages()->create([
            'date' => today()->toDateString(),
            'requests' => 2,
            'analyses' => 1,
            'errors' => 0,
        ]);
        $user->analysisUsages()->create(['date' => today()->toDateString(), 'analyses' => 1]);

        $imagePath = 'ocr-images/test-capture.png';
        Storage::disk('local')->put($imagePath, 'test image');
        $article = Article::query()->create([
            'created_by' => $user->id,
            'url' => null,
            'title' => 'Artículo OCR',
            'content' => 'Texto reconocido de una captura guardada.',
            'metadata' => ['ocr_image_path' => $imagePath],
        ]);
        $analysis = Analysis::query()->create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'status' => 'completed',
            'algorithm_version' => '1.0.0',
        ]);
        $claim = Claim::query()->create([
            'analysis_id' => $analysis->id,
            'text' => 'Afirmación de prueba',
            'fingerprint' => 'claim-fingerprint',
        ]);
        $evidence = Evidence::query()->create([
            'url' => 'https://example.test/evidence',
            'title' => 'Evidencia de prueba',
        ]);
        $verification = ClaimVerification::query()->create([
            'claim_id' => $claim->id,
            'relation' => 'search_evidence',
            'fingerprint' => 'verification-fingerprint',
        ]);
        $verification->evidences()->attach($evidence->id);
        Score::query()->create([
            'analysis_id' => $analysis->id,
            'final_score' => 50,
            'algorithm_version' => '1.0.0',
        ]);

        $this->withToken($token)->deleteJson('/api/v1/account', ['password' => 'CurrentPass123'])
            ->assertOk()
            ->assertJsonPath('message', 'La cuenta y los datos asociados se eliminaron.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('api_keys', ['id' => $apiKey->id]);
        $this->assertDatabaseMissing('api_usages', ['api_key_id' => $apiKey->id]);
        $this->assertDatabaseMissing('social_accounts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('user_analysis_usages', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('articles', ['id' => $article->id]);
        $this->assertDatabaseMissing('analyses', ['id' => $analysis->id]);
        $this->assertDatabaseMissing('claims', ['id' => $claim->id]);
        $this->assertDatabaseMissing('claim_verifications', ['id' => $verification->id]);
        $this->assertDatabaseMissing('scores', ['analysis_id' => $analysis->id]);
        $this->assertDatabaseMissing('verification_evidence', ['evidence_id' => $evidence->id]);
        $this->assertDatabaseMissing('evidences', ['id' => $evidence->id]);
        Storage::disk('local')->assertMissing($imagePath);
    }

    public function test_recent_linked_google_identity_can_reauthenticate_account_deletion(): void
    {
        config(['services.google.client_id' => 'test-google-client-id']);
        $user = User::factory()->create(['password' => null]);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => $user->email,
        ]);
        Sanctum::actingAs($user);
        $this->useGoogleClaims([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'exp' => CarbonImmutable::now()->addMinute()->timestamp,
            'iat' => CarbonImmutable::now()->timestamp,
            'email_verified' => true,
            'sub' => 'google-subject-1',
            'email' => $user->email,
            'name' => $user->name,
        ]);

        $this->deleteJson('/api/v1/account', ['credential' => 'fresh-google-id-token'])
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_old_google_identity_cannot_reauthenticate_account_deletion(): void
    {
        config(['services.google.client_id' => 'test-google-client-id']);
        $user = User::factory()->create(['password' => null]);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'google-subject-1',
            'email' => $user->email,
        ]);
        Sanctum::actingAs($user);
        $this->useGoogleClaims([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'exp' => CarbonImmutable::now()->addMinute()->timestamp,
            'iat' => CarbonImmutable::now()->subMinutes(6)->timestamp,
            'email_verified' => true,
            'sub' => 'google-subject-1',
            'email' => $user->email,
            'name' => $user->name,
        ]);

        $this->deleteJson('/api/v1/account', ['credential' => 'old-google-id-token'])
            ->assertForbidden()
            ->assertJsonPath('code', 'google_reauthentication_expired');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_google_identity_must_be_linked_to_the_authenticated_user(): void
    {
        config(['services.google.client_id' => 'test-google-client-id']);
        $user = User::factory()->create(['password' => null]);
        $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_user_id' => 'another-subject',
            'email' => $user->email,
        ]);
        Sanctum::actingAs($user);
        $this->useGoogleClaims([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-google-client-id',
            'exp' => CarbonImmutable::now()->addMinute()->timestamp,
            'iat' => CarbonImmutable::now()->timestamp,
            'email_verified' => true,
            'sub' => 'unlinked-subject',
            'email' => 'other@example.com',
            'name' => 'Other',
        ]);

        $this->deleteJson('/api/v1/account', ['credential' => 'unlinked-google-id-token'])
            ->assertForbidden()
            ->assertJsonPath('code', 'google_reauthentication_failed');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /** @param array<string, mixed> $claims */
    private function useGoogleClaims(array $claims): void
    {
        $this->app->instance(GoogleIdTokenVerifierInterface::class, new AccountDeletionGoogleVerifier($claims));
    }
}

final class AccountDeletionGoogleVerifier implements GoogleIdTokenVerifierInterface
{
    /** @param array<string, mixed> $claims */
    public function __construct(private readonly array $claims) {}

    public function verify(string $idToken): ?array
    {
        if ($idToken === '') {
            throw new GoogleVerificationUnavailableException;
        }

        return $this->claims;
    }
}
