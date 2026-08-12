<?php

namespace Tests\Feature;

use App\Mail\PasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password_and_other_tokens_are_revoked(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/password', [
                'current_password' => 'old-password-1',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertOk()
            ->assertJson(['message' => 'Password updated.']);

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertNull($otherToken->accessToken->fresh());
        // The current session's token survives; everything else is revoked.
        $this->assertNotNull($currentToken->accessToken->fresh());
        $this->assertSame(1, $user->fresh()->tokens()->count());
        // The current session still works after the change.
        $this->withToken($currentToken->plainTextToken)->getJson('/api/user')->assertOk();
    }

    public function test_password_change_queues_the_changed_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'old-password-1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        Mail::assertQueued(PasswordChanged::class);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_short_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'different-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/password', [
            'current_password' => 'x',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(401);
    }

    public function test_endpoint_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ]);
        }

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(429);
    }

    public function test_password_changed_email_is_mobile_friendly(): void
    {
        $user = User::factory()->create(['email' => 'customer@example.com']);

        $html = (new PasswordChanged($user))->render();

        $this->assertStringContainsString('@media screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringNotContainsString('min-width: 560px', $html);
        $this->assertStringContainsString('Guinobatan Waterworks', $html);
        $this->assertStringContainsString('customer@example.com', $html);
        $this->assertStringContainsString('Contact us', $html);
    }
}
