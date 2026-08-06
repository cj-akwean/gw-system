<?php

namespace App\Jobs;

use App\Mail\PaymentConfirmation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
        if ($this->invoice->serviceConnection === null) {
            Log::channel('paymongo')->warning('Payment confirmation email skipped: invoice has no service connection', [
                'invoice_id' => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
            ]);

            return;
        }

        $recipients = static::recipientsFor($this->invoice);

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

    /**
     * Resolve the distinct, lowercase, valid email addresses that should
     * receive the confirmation for the given invoice. Empty when the invoice
     * has no service connection or no active linked user has a valid email.
     *
     * @return array<int, string>
     */
    public static function recipientsFor(Invoice $invoice): array
    {
        $connection = $invoice->serviceConnection;

        if ($connection === null) {
            return [];
        }

        return $connection->connectionLinks()
            ->where('status', 'active')
            ->whereNull('unlinked_at')
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
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('paymongo')->error('Payment confirmation email failed permanently', [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'payment_id' => $this->payment->id,
            'error' => $exception?->getMessage(),
        ]);

        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Payment confirmation email failed')
            ->body(
                'Invoice '.$this->invoice->invoice_number
                .' (payment #'.$this->payment->id.') never reached the customer.'
            )
            ->actions([
                Action::make('resendReceipt')
                    ->label('Resend receipt')
                    ->button()
                    ->color('primary')
                    ->url(route('admin.payments.resend-receipt', $this->payment)),
            ])
            ->sendToDatabase($admins);
    }
}
