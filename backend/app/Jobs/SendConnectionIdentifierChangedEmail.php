<?php

namespace App\Jobs;

use App\Mail\ConnectionIdentifiersChanged;
use App\Models\ServiceConnection;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails every portal user with an active link to a service connection whose
 * account/meter numbers were changed by an admin, so the change is never
 * invisible to the customer. Skipped when nobody is linked (nothing to notify).
 *
 * Unique per (connection, changed identifiers, recipients): a dupe edit to the
 * same identifiers is dropped while the first notification is still queued or
 * being delivered, so a double-save never double-emails a customer.
 */
#[UniqueFor(3600)]
class SendConnectionIdentifierChangedEmail implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, string>  $oldIdentifiers  account_number / meter_number before the change
     * @param  array<int, string>  $recipients  distinct, lowercase emails to notify (resolved at save time)
     */
    public function __construct(
        public ServiceConnection $serviceConnection,
        public int $serviceConnectionId,
        public array $oldIdentifiers,
        public array $recipients,
    ) {}

    public function uniqueId(): string
    {
        return 'conn-'.$this->serviceConnectionId.'-'.hash(
            'xxh128',
            json_encode([$this->oldIdentifiers, $this->recipients]),
        );
    }

    public function handle(): void
    {
        if ($this->recipients === []) {
            Log::channel('paymongo')->warning('Connection identifier change email skipped: no linked users with a valid email', [
                'service_connection_id' => $this->serviceConnectionId,
                'account_number' => $this->serviceConnection->account_number,
            ]);

            return;
        }

        Mail::to($this->recipients)->send(new ConnectionIdentifiersChanged($this->serviceConnection, $this->oldIdentifiers));

        Log::channel('paymongo')->info('Connection identifier change email sent', [
            'service_connection_id' => $this->serviceConnectionId,
            'old_identifiers' => $this->oldIdentifiers,
            'recipients' => $this->recipients,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('paymongo')->error('Connection identifier change email failed permanently', [
            'service_connection_id' => $this->serviceConnectionId,
            'old_identifiers' => $this->oldIdentifiers,
            'recipients' => $this->recipients,
            'error' => $exception?->getMessage(),
        ]);

        $admins = User::where('is_admin', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Identifier change email failed')
            ->body(
                'Identifier change for connection #'.$this->serviceConnectionId
                .' never reached the customer(s). Fix the mailer, then re-save the connection to retry.'
            )
            ->sendToDatabase($admins);
    }
}
