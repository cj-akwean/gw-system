<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;

/**
 * Password-reset tokens are 6-digit OTPs instead of long random strings, so
 * the same Laravel broker machinery (hashing, single-use, 60s resend throttle,
 * expiry) powers the email-OTP reset flows on both the admin panel and the
 * customer portal.
 */
class OtpTokenRepository extends DatabaseTokenRepository
{
    public function createNewToken(): string
    {
        return (string) random_int(100000, 999999);
    }
}
