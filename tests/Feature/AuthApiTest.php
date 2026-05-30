<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receive_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trader',
            'email' => 'trader@example.com',
            'password' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'trader@example.com')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'trader@example.com']);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'trader@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'trader@example.com',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'trader@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'trader@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Trader',
            'email' => 'trader@example.com',
            'password' => 'secret123',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'trader@example.com')
            ->assertJsonPath('onboarded', false);
    }
}
