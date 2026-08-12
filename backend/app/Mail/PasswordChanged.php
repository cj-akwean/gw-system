<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the account owner after a password change (admin profile page or
 * customer portal settings), so an unauthorized change is never silent.
 */
class PasswordChanged extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password has been changed',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.password-changed-html',
            text: 'emails.password-changed-text',
        );
    }
}
