<?php

namespace Tests\Feature;

use App\Mail\PasswordChangeOtp;
use App\Services\OtpService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email' => 'otp@example.com']);
    }

    private function send(User $user): string
    {
        Mail::fake();
        app(OtpService::class)->send($user, OtpService::PASSWORD_CHANGE);

        $mailable = Mail::queued(PasswordChangeOtp::class)->first();

        return $mailable->code;
    }

    public function test_send_queues_a_six_digit_code(): void
    {
        $code = $this->send($this->user());

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        Mail::assertQueued(PasswordChangeOtp::class);
    }

    public function test_correct_code_verifies_and_is_consumed(): void
    {
        $user = $this->user();
        $code = $this->send($user);

        $this->assertTrue(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $code));
        // Single-use: the same code cannot be verified twice.
        $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $code));
    }

    public function test_wrong_code_fails_and_counts_attempts(): void
    {
        $user = $this->user();
        $code = $this->send($user);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, '000000'));
        }

        // After 5 failed attempts the code is invalidated — even the right one.
        $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $code));
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = $this->user();
        $code = $this->send($user);

        // Simulate expiry: push the stored code's expiry into the past.
        $payload = Cache::get('otp:'.$user->id.':'.OtpService::PASSWORD_CHANGE);
        Cache::put('otp:'.$user->id.':'.OtpService::PASSWORD_CHANGE, [
            ...$payload,
            'expires_at' => now()->subSecond()->timestamp,
        ]);

        $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $code));
    }

    public function test_verify_without_a_code_returns_false(): void
    {
        $user = $this->user();

        $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, '123456'));
    }

    public function test_resend_replaces_the_previous_code(): void
    {
        $user = $this->user();
        $first = $this->send($user);
        $second = $this->send($user);

        $this->assertNotSame($first, $second);
        $this->assertTrue(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $second));
        $this->assertFalse(app(OtpService::class)->verify($user, OtpService::PASSWORD_CHANGE, $first));
    }
}
