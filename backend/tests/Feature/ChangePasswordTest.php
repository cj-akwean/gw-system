<?php

namespace Tests\Feature;

use App\Jobs\SendOtpSms;
use App\Mail\PasswordChangeOtp;
use App\Mail\PasswordChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::factory()->create([
            'email' => 'customer@example.com',
            'password' => 'old-password-1',
            ...$overrides,
        ]);
    }

    private function requestCode(User $user): string
    {
        Mail::fake();
        $token = $user->createToken('code-request')->plainTextToken;

        $this->withToken($token)->postJson('/api/password/send-code')->assertOk();

        // The sanctum guard caches the authenticated user (with the token used
        // above) across requests in one test — drop it so the next request
        // authenticates with its own token.
        $this->app['auth']->forgetGuards();

        $mailable = Mail::queued(PasswordChangeOtp::class)->first();

        return $mailable->code;
    }

    public function test_user_can_change_password_with_otp_and_other_tokens_are_revoked(): void
    {
        $user = $this->user();
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device');
        $code = $this->requestCode($user);

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/password', [
                'current_password' => 'old-password-1',
                'otp' => $code,
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])->assertOk()
            ->assertJson(['message' => 'Password updated.']);

        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
        $this->assertNull($otherToken->accessToken->fresh());
        $this->assertNotNull($currentToken->accessToken->fresh());
        $this->assertSame(1, $user->fresh()->tokens()->count());
        $this->withToken($currentToken->plainTextToken)->getJson('/api/user')->assertOk();
    }

    public function test_password_change_queues_the_changed_email(): void
    {
        Mail::fake();

        $user = $this->user();
        $code = $this->requestCode($user);
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        Mail::assertQueued(PasswordChanged::class);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->postJson('/api/password', [
            'current_password' => 'wrong-password',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_missing_or_wrong_otp_is_rejected(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'otp' => '000000',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
    }

    public function test_otp_is_single_use(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $payload = [
            'current_password' => 'old-password-1',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ];

        $this->postJson('/api/password', $payload)->assertOk();

        $this->postJson('/api/password', $payload)->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
    }

    public function test_short_password_is_rejected(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'otp' => $code,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_mismatched_confirmation_is_rejected(): void
    {
        $user = $this->user();
        $code = $this->requestCode($user);

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'otp' => $code,
            'password' => 'new-password-1',
            'password_confirmation' => 'different-1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/password', [
            'current_password' => 'x',
            'otp' => '123456',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(401);

        $this->postJson('/api/password/send-code')->assertStatus(401);
    }

    public function test_send_code_endpoint_is_rate_limited(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/password/send-code');
        }

        $this->postJson('/api/password/send-code')->assertStatus(429);
    }

    public function test_send_code_with_sms_channel_dispatches_sms_and_returns_phone_message(): void
    {
        Queue::fake();

        $user = $this->user(['phone' => '09171234567']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password/send-code', ['channel' => 'sms'])
            ->assertOk()
            ->assertJson(['message' => 'Verification code sent to your phone (09171234567).']);

        Queue::assertPushed(SendOtpSms::class, fn (SendOtpSms $job): bool => $job->phone === '09171234567');
    }

    public function test_send_code_with_sms_channel_without_a_phone_returns_422(): void
    {
        Queue::fake();

        $user = $this->user(['phone' => null]);
        Sanctum::actingAs($user);

        $this->postJson('/api/password/send-code', ['channel' => 'sms'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Add a phone number in Settings first.']);

        Queue::assertNothingPushed();
    }

    public function test_send_code_defaults_to_email_and_returns_email_message(): void
    {
        Mail::fake();
        Queue::fake();

        $user = $this->user(['phone' => '09171234567']);
        Sanctum::actingAs($user);

        $this->postJson('/api/password/send-code')
            ->assertOk()
            ->assertJson(['message' => 'Verification code sent to your email.']);

        Mail::assertQueued(PasswordChangeOtp::class);
        Queue::assertNotPushed(SendOtpSms::class);
    }

    public function test_send_code_rejects_an_invalid_channel(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/password/send-code', ['channel' => 'fax'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_endpoint_is_rate_limited(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/password', [
                'current_password' => 'wrong-password',
                'otp' => '000000',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ]);
        }

        $this->postJson('/api/password', [
            'current_password' => 'old-password-1',
            'otp' => '000000',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertStatus(429);
    }

    public function test_password_changed_email_is_mobile_friendly(): void
    {
        $user = $this->user(['email' => 'customer@example.com']);

        $html = (new \App\Mail\PasswordChanged($user))->render();

        $this->assertStringContainsString('@media screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringNotContainsString('min-width: 560px', $html);
        $this->assertStringContainsString('Guinobatan Waterworks', $html);
        $this->assertStringContainsString('customer@example.com', $html);
        $this->assertStringContainsString('Contact us', $html);
    }

    public function test_change_otp_email_is_mobile_friendly(): void
    {
        $user = $this->user(['email' => 'customer@example.com']);

        $html = (new \App\Mail\PasswordChangeOtp($user, '123456'))->render();

        $this->assertStringContainsString('@media screen and (max-width: 600px)', $html);
        $this->assertStringContainsString('width="600"', $html);
        $this->assertStringNotContainsString('min-width: 560px', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('Guinobatan Waterworks', $html);
    }
}
