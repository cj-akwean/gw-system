<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Mail\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
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

    public function test_request_with_sms_channel_dispatches_sms_with_the_broker_token(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::factory()->create([
            'email' => 'lost-sms@example.com',
            'phone' => '09171234567',
            'password' => 'old-password-1',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'lost-sms@example.com',
            'channel' => 'sms',
        ])->assertOk()
            ->assertJson(['message' => 'If an account exists for that email, a verification code is on its way.']);

        $job = Queue::pushed(SendOtpSms::class)->first();

        $this->assertNotNull($job);
        $this->assertSame('09171234567', $job->phone);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $job->code);
        Mail::assertNothingQueued();

        // The SMS-delivered token is the real broker token — it resets the password.
        $this->postJson('/api/reset-password', [
            'email' => 'lost-sms@example.com',
            'otp' => $job->code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_request_with_sms_channel_falls_back_to_email_when_no_phone(): void
    {
        Mail::fake();
        Queue::fake();

        User::factory()->create(['email' => 'lost-nophone@example.com', 'phone' => null]);

        $this->postJson('/api/forgot-password', [
            'email' => 'lost-nophone@example.com',
            'channel' => 'sms',
        ])->assertOk()
            ->assertJson(['message' => 'If an account exists for that email, a verification code is on its way.']);

        Mail::assertQueued(PasswordResetOtp::class);
        Queue::assertNotPushed(SendOtpSms::class);
    }

    public function test_request_defaults_to_email_even_when_the_user_has_a_phone(): void
    {
        Mail::fake();
        Queue::fake();

        User::factory()->create([
            'email' => 'lost-email-first@example.com',
            'phone' => '09171234567',
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'lost-email-first@example.com'])
            ->assertOk();

        Mail::assertQueued(PasswordResetOtp::class);
        Queue::assertNotPushed(SendOtpSms::class);
    }

    public function test_request_with_sms_channel_skips_unknown_emails_without_sms_or_email(): void
    {
        Mail::fake();
        Queue::fake();

        $this->postJson('/api/forgot-password', [
            'email' => 'nobody-sms@example.com',
            'channel' => 'sms',
        ])->assertOk();

        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
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
