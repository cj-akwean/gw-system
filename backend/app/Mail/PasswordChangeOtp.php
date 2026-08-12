<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 6-digit code required before a signed-in user's password can be changed
 * (admin profile page and customer portal settings). Valid 5 minutes,
 * single-use, 5 attempts.
 */
class PasswordChangeOtp extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.password-change-otp-html',
            text: 'emails.password-change-otp-text',
        );
    }
}
