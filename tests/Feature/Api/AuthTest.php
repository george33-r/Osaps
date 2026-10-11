<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_client_registers_and_receives_a_token(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Obi', 'email' => 'ada@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => 'customer', 'device_name' => 'iPhone 15',
        ]);

        $res->assertCreated()->assertJsonPath('data.role', 'customer')->assertJsonStructure(['token']);
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$res->json('token')])
            ->assertOk()->assertJsonPath('data.email', 'ada@example.com');
    }

    public function test_admin_role_cannot_be_self_assigned(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sneaky', 'email' => 'x@example.com', 'password' => 'secret123',
            'password_confirmation' => 'secret123', 'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_login_does_not_reveal_whether_email_exists(): void
    {
        User::factory()->create(['email' => 'real@example.com']);

        $wrongPassword = $this->postJson('/api/v1/auth/login', ['email' => 'real@example.com', 'password' => 'nope', 'device_name' => 'x']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope', 'device_name' => 'x']);

        $wrongPassword->assertStatus(422);
        $unknown->assertStatus(422);
        $this->assertSame($wrongPassword->json('errors'), $unknown->json('errors'));
    }

    public function test_logout_revokes_the_mobile_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_endpoints_return_consistent_401(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }
}
