<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_wrong_password_returns_generic_error(): void
    {
        User::factory()->create(['email' => 'customer@example.com']);

        $this->postJson('/api/login', [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Incorrect email or password.']);
    }

    public function test_login_with_unknown_email_returns_same_generic_error(): void
    {
        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])
            ->assertStatus(401)
            ->assertJson(['message' => 'Incorrect email or password.']);
    }

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/api/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_login_endpoint_is_throttled_after_ten_attempts(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }
}
