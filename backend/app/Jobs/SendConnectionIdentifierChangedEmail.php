<?php

namespace App\Jobs;

use App\Mail\ConnectionIdentifiersChanged;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\ServiceConnectionService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails every portal user with an active link to a service connection whose
 * account/meter numbers were changed by an admin, so the change is never
 * invisible to the customer. Skipped when nobody is linked (nothing to notify).
 */
class SendConnectionIdentifierChangedEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, string>  $oldIdentifiers  account_number / meter_number before the change
     */
    public function __construct(
        public ServiceConnection $serviceConnection,
        public array $oldIdentifiers,
    ) {}

    public function handle(): void
    {
        $recipients = ServiceConnectionService::recipientsFor($this->serviceConnection);

        if ($recipients === []) {
            Log::channel('paymongo')->warning('Connection identifier change email skipped: no linked users with a valid email', [
                'service_connection_id' => $this->serviceConnection->id,
                'account_number' => $this->serviceConnection->account_number,
            ]);

            return;
        }

        Mail::to($recipients)->send(new ConnectionIdentifiersChanged($this->serviceConnection, $this->oldIdentifiers));

        Log::channel('paymongo')->info('Connection identifier change email sent', [
            'service_connection_id' => $this->serviceConnection->id,
            'old_identifiers' => $this->oldIdentifiers,
            'recipients' => $recipients,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('paymongo')->error('Connection identifier change email failed permanently', [
            'service_connection_id' => $this->serviceConnection->id,
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
                'Account number change for connection #'.$this->serviceConnection->account_number
                .' never reached the customer(s). Fix the mailer, then re-save the connection to retry.'
            )
            ->sendToDatabase($admins);
    }
}
