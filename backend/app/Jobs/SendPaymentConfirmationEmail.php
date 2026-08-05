<?php

namespace App\Jobs;

use App\Mail\PaymentConfirmation;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the payment confirmation (with the itemized invoice PDF attached) to
 * every portal user with an active link to the invoice's service connection.
 *
 * Multiple boarders splitting one bill all receive the confirmation; identical
 * emails are deduped. When no linked user has an email address the job logs and
 * skips — the payment itself is already recorded by then, so nothing is lost.
 */
class SendPaymentConfirmationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public Invoice $invoice,
        public Payment $payment,
    ) {}

    public function handle(): void
    {
        $recipients = $this->invoice->serviceConnection
            ?->connectionLinks()
            ->where('status', 'active')
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->filter(function (mixed $email): bool {
                return is_string($email)
                    && trim($email) !== ''
                    && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
            })
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            Log::channel('paymongo')->warning('Payment confirmation email skipped: no linked users with a valid email', [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
            ]);

            return;
        }

        Mail::to($recipients)->send(new PaymentConfirmation($this->invoice, $this->payment));

        Log::channel('paymongo')->info('Payment confirmation email sent', [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'recipients' => $recipients,
        ]);
    }
}
