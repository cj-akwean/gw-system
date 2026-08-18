<?php

namespace App\Services;

use App\Jobs\SendOtpSms;
use App\Mail\PasswordChangeOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * Verification OTPs for authenticated actions (currently: password changes on
 * the admin profile page and the customer portal). Codes are 6 digits, valid 5
 * minutes, single-use, capped at 5 verification attempts, and stored hashed
 * in the cache (database driver). Resending replaces the previous code and
 * resets the attempt counter.
 *
 * Delivery runs on a channel chosen by the caller — `email` (default) or
 * `sms` (Semaphore). The code is generated and cached the same way for both;
 * only the delivery differs, so `verify()` is channel-agnostic.
 */
class OtpService
{
    public const PASSWORD_CHANGE = 'password_change';

    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    public function send(User $user, string $purpose, string $channel = 'email'): void
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException('Unsupported OTP delivery channel: '.$channel);
        }

        if ($channel === 'sms' && ! $this->hasPhone($user)) {
            throw new InvalidArgumentException('User has no phone number to receive the code.');
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($user, $purpose), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'channel' => $channel,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ], self::TTL_SECONDS);

        if ($channel === 'sms') {
            SendOtpSms::dispatch($user->phone, $code, SmsService::OTP_MESSAGE);

            return;
        }

        Mail::to($user)->queue(new PasswordChangeOtp($user, $code));
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $payload = Cache::get($this->key($user, $purpose));

        if (! is_array($payload) || empty($payload['hash'])) {
            return false;
        }

        if (($payload['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            Cache::forget($this->key($user, $purpose));

            return false;
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            Cache::forget($this->key($user, $purpose));

            return false;
        }

        $valid = Hash::check($code, (string) $payload['hash']);

        if ($valid) {
            Cache::forget($this->key($user, $purpose));

            return true;
        }

        Cache::put($this->key($user, $purpose), [
            ...$payload,
            'attempts' => ($payload['attempts'] ?? 0) + 1,
        ], self::TTL_SECONDS);

        return false;
    }

    private function key(User $user, string $purpose): string
    {
        return 'otp:'.$user->getKey().':'.$purpose;
    }

    private function hasPhone(User $user): bool
    {
        return is_string($user->phone) && trim($user->phone) !== '';
    }
}
