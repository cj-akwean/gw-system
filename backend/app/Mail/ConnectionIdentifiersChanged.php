<?php

namespace App\Mail;

use App\Models\ServiceConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Emails linked portal users when an admin changes a connection's account
 * and/or meter number, so a renumbering never happens silently on the
 * admin side only (existing portal links are FK-based and unaffected).
 */
class ConnectionIdentifiersChanged extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, string>  $oldIdentifiers  account_number / meter_number before the change
     */
    public function __construct(
        public ServiceConnection $serviceConnection,
        public array $oldIdentifiers,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your water account details have been updated',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.connection-identifiers-changed-html',
            text: 'emails.connection-identifiers-changed-text',
        );
    }
}
