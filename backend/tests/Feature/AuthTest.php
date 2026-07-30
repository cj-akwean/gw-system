<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_user_can_logout_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        $token = $loginResponse->json('token');

        $logoutResponse = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson('/api/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['message' => 'Logged out']);

        $this->assertEquals(0, $user->tokens()->count());
    }

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
