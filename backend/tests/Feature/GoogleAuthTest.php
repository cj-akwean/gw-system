<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'test-client-id.apps.googleusercontent.com']);
    }

    private function fakeTokenInfo(array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(array_merge([
                'iss' => 'https://accounts.google.com',
                'azp' => 'test-client-id.apps.googleusercontent.com',
                'aud' => 'test-client-id.apps.googleusercontent.com',
                'sub' => 'google-sub-123',
                'email' => 'google@example.com',
                'email_verified' => 'true',
                'name' => 'Google User',
            ], $overrides)),
        ]);
    }

    public function test_new_google_user_is_created_and_logged_in(): void
    {
        $this->fakeTokenInfo();

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'a-valid-jwt',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'email' => 'google@example.com',
            'google_id' => 'google-sub-123',
            'name' => 'Google User',
        ]);
    }

    public function test_existing_google_user_logs_in_without_duplicating(): void
    {
        User::factory()->create([
            'email' => 'google@example.com',
            'google_id' => 'google-sub-123',
            'password' => null,
        ]);

        $this->fakeTokenInfo();

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'a-valid-jwt',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertEquals(1, User::where('email', 'google@example.com')->count());
    }

    public function test_existing_email_without_google_id_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'google@example.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->fakeTokenInfo();

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'a-valid-jwt',
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => 'An account with this email already exists. Please log in with your email and password.',
            ]);

        $this->assertNull(User::where('email', 'google@example.com')->first()->google_id);
    }

    public function test_invalid_credential_is_rejected(): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'error_description' => 'Invalid Value',
            ], 400),
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'garbage',
        ]);

        $response->assertStatus(401);
    }

    public function test_token_minted_for_another_client_is_rejected(): void
    {
        $this->fakeTokenInfo([
            'aud' => 'some-other-client-id.apps.googleusercontent.com',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'a-valid-jwt',
        ]);

        $response->assertStatus(401);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeTokenInfo([
            'email_verified' => 'false',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'credential' => 'a-valid-jwt',
        ]);

        $response->assertStatus(401);
    }

    public function test_credential_is_required(): void
    {
        $response = $this->postJson('/api/auth/google', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['credential']);
    }
}