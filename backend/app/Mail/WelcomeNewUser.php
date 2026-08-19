<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Sent to a customer right after they register, welcoming them to the portal.
 */
class WelcomeNewUser extends Mailable
{
    use Queueable, SerializesModels;

    public string $greeting;

    public function __construct(public User $user)
    {
        $this->greeting = $user->name ?: Str::before($user->email, '@');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Guinobatan Waterworks',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.welcome-new-user-html',
            text: 'emails.welcome-new-user-text',
        );
    }
}