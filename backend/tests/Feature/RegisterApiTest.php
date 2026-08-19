<?php

namespace Tests\Feature;

use App\Mail\WelcomeNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegisterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_email_and_password(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register', [
            'email' => 'newbie@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'avatar_id']])
            ->assertJsonPath('user.email', 'newbie@example.com')
            ->assertJsonPath('user.name', null)
            ->assertJsonPath('user.avatar_id', null);

        $this->assertDatabaseHas('users', [
            'email' => 'newbie@example.com',
            'name' => null,
        ]);

        Mail::assertQueued(WelcomeNewUser::class);

        $token = $response->json('token');
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'newbie@example.com');
    }

    public function test_register_returns_422_for_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'email' => 'taken@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJson(['message' => 'An account with this email already exists.']);
    }

    public function test_register_rejects_short_password(): void
    {
        $this->postJson('/api/register', [
            'email' => 'shorty@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'email' => 'mismatch@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'different',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_valid_email(): void
    {
        $this->postJson('/api/register', [
            'email' => 'not-an-email',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_endpoint_is_throttled_after_ten_attempts(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.77']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/register', [
                'email' => "spam-$i@example.com",
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])->assertStatus(201);
        }

        $this->postJson('/api/register', [
            'email' => 'spam-10@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(429);
    }
}
