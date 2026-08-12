<?php

namespace App\Services;

use App\Mail\PasswordChangeOtp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Email OTPs for authenticated actions (currently: password changes on the
 * admin profile page and the customer portal). Codes are 6 digits, valid 5
 * minutes, single-use, capped at 5 verification attempts, and stored hashed
 * in the cache (database driver). Resending replaces the previous code and
 * resets the attempt counter.
 */
class OtpService
{
    public const PASSWORD_CHANGE = 'password_change';

    private const TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    public function send(User $user, string $purpose): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($user, $purpose), [
            'hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS)->timestamp,
        ], self::TTL_SECONDS);

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
}
