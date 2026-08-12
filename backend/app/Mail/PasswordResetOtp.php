<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 6-digit code for resetting a forgotten password (admin panel and customer
 * portal). The code is the broker's reset token: single-use, expires in 15
 * minutes, hashed in the password_reset_tokens table.
 */
class PasswordResetOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password reset code',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.password-reset-otp-html',
            text: 'emails.password-reset-otp-text',
        );
    }
}
