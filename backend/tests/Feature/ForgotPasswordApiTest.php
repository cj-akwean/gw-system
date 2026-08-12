<?php

namespace Tests\Feature;

use App\Mail\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ForgotPasswordApiTest extends TestCase
{
    use RefreshDatabase;

    private function requestCode(string $email): string
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => $email])->assertOk();

        $mailable = Mail::queued(PasswordResetOtp::class)->first();

        return $mailable->code;
    }

    public function test_request_sends_a_six_digit_code(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $code = $this->requestCode('lost@example.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertQueued(PasswordResetOtp::class);
    }

    public function test_request_returns_generic_message_for_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJson(['message' => 'If an account exists for that email, a verification code is on its way.']);

        Mail::assertNothingQueued();
    }

    public function test_request_is_throttled(): void
    {
        User::factory()->create(['email' => 'lost@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'lost@example.com']);
        }

        $this->postJson('/api/forgot-password', ['email' => 'lost@example.com'])->assertStatus(429);
    }

    public function test_reset_with_the_otp_changes_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'lost@example.com',
            'password' => 'old-password-1',
        ]);
        $user->createToken('old-device');
        $code = $this->requestCode('lost@example.com');

        $this->postJson('/api/reset-password', [
            'email' => 'lost@example.com',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk()
            ->assertJson(['message' => 'Password reset. You can now sign in.']);

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_reset_with_a_wrong_otp_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'lost@example.com',
            'password' => 'old-password-1',
        ]);
        $this->requestCode('lost@example.com');

        $this->postJson('/api/reset-password', [
            'email' => 'lost@example.com',
            'otp' => '000000',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_reset_otp_is_single_use(): void
    {
        $user = User::factory()->create([
            'email' => 'lost@example.com',
            'password' => 'old-password-1',
        ]);
        $code = $this->requestCode('lost@example.com');

        $payload = [
            'email' => 'lost@example.com',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();

        $this->postJson('/api/reset-password', $payload)->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
    }

    public function test_reset_rejects_short_password(): void
    {
        $user = User::factory()->create([
            'email' => 'lost@example.com',
            'password' => 'old-password-1',
        ]);
        $code = $this->requestCode('lost@example.com');

        $this->postJson('/api/reset-password', [
            'email' => 'lost@example.com',
            'otp' => $code,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_is_throttled(): void
    {
        $user = User::factory()->create([
            'email' => 'lost@example.com',
            'password' => 'old-password-1',
        ]);
        $this->requestCode('lost@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', [
                'email' => 'lost@example.com',
                'otp' => '000000',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ]);
        }

        $this->postJson('/api/reset-password', [
            'email' => 'lost@example.com',
            'otp' => '000000',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(429);
    }

    public function test_reset_otp_email_is_mobile_friendly(): void
    {
        $user = User::factory()->create(['email' => 'lost@example.com']);

        $html = (new PasswordResetOtp($user, '654321'))->render();

        $this->assertStringContainsString('@media screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringNotContainsString('min-width: 560px', $html);
        $this->assertStringContainsString('654321', $html);
        $this->assertStringContainsString('Guinobatan Waterworks', $html);
    }
}
