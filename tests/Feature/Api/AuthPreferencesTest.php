<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthPreferencesTest extends TestCase
{
    public function test_authenticated_user_can_save_and_load_allowlisted_preferences(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $preferences = [
            'theme' => 'dark',
            'fontScale' => 1.15,
            'highContrast' => true,
            'reduceMotion' => false,
            'lineHeight' => 'relaxed',
            'density' => 'compact',
            'fontFamily' => 'atkinson',
        ];

        $this->putJson('/api/v1/auth/preferences', ['preferences' => $preferences])
            ->assertOk()
            ->assertJsonPath('data.preferences.theme', 'dark')
            ->assertJsonPath('data.preferences.fontScale', 1.15)
            ->assertJsonPath('data.preferences.fontFamily', 'atkinson');

        $this->assertSame($preferences, $user->fresh()->preferences);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.preferences', $preferences);
    }

    public function test_invalid_preferences_are_rejected_without_persisting_them(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/preferences', [
            'preferences' => [
                'theme' => 'sepia',
                'fontScale' => 1.5,
                'highContrast' => true,
                'reduceMotion' => false,
                'lineHeight' => 'huge',
                'density' => 'compact',
                'fontFamily' => 'remote-font',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_preferences')
            ->assertJsonValidationErrors([
                'preferences.theme',
                'preferences.fontScale',
                'preferences.lineHeight',
                'preferences.fontFamily',
            ]);

        $this->assertNull($user->fresh()->preferences);
    }

    public function test_preferences_reject_unrecognized_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/preferences', [
            'preferences' => [
                'theme' => 'system',
                'fontScale' => 1,
                'highContrast' => false,
                'reduceMotion' => false,
                'lineHeight' => 'normal',
                'density' => 'comfortable',
                'fontFamily' => 'system',
                'customCss' => 'body { display: none }',
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_preferences')
            ->assertJsonValidationErrors('preferences');

        $this->assertNull($user->fresh()->preferences);
    }
}
