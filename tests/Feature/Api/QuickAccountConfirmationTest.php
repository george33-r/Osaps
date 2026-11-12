<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickAccountConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unconfirmed_account_cannot_start_checkout(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)->postJson('/api/v1/checkouts', [])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_confirmed');
    }

    public function test_setting_a_password_from_the_emailed_link_confirms_the_email(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Fresh12345',
            'password_confirmation' => 'Fresh12345',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }
}
