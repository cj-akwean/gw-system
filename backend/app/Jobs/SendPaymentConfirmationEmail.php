<?php

namespace App\Jobs;

use App\Mail\PaymentConfirmation;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
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

        AdminNotifier::notify(
            'Receipt sent',
            'Invoice '.$this->invoice->invoice_number.' — payment receipt emailed to '.count($recipients).' recipient(s).',
        );
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

        $admins = AdminNotifier::notify(
            'Payment confirmation email failed',
            'Invoice '.$this->invoice->invoice_number
            .' (payment #'.$this->payment->id.') never reached the customer.',
            'danger',
            'Resend receipt',
            $this->resendPath(),
            'resendReceipt',
        );

        if ($admins->isNotEmpty()) {
            $this->tagNotifications($admins);
        }
    }

    /**
     * The resend route as a host-independent path (e.g. `/admin/payments/8/
     * resend-receipt`). The stored action URL must never embed an absolute
     * host: the same notification row is read back in different environments
     * (dev on 127.0.0.1:8000, XAMPP on localhost, prod domain), and resolution
     * matches rows by this path suffix. A relative href also renders correctly
     * from any origin in the admin UI.
     */
    private function resendPath(): string
    {
        return (string) parse_url(route('admin.payments.resend-receipt', $this->payment), PHP_URL_PATH);
    }

    /**
     * Stamps the just-created failure notifications with the payment and
     * invoice ids so the resend controller can find them later (to mark the
     * notification resolved and to block duplicate resends). Rows are matched
     * by the action URL's path suffix — unique per payment, host-independent,
     * so no creation-order heuristics are needed and repeated failures never
     * double-tag.
     *
     * @param  Collection<int, User>  $admins
     */
    private function tagNotifications($admins): void
    {
        $path = $this->resendPath();

        DatabaseNotification::query()
            ->whereIn('notifiable_id', $admins->modelKeys())
            ->whereNull('read_at')
            ->where('data->format', 'filament')
            ->where('data->actions->0->url', 'like', '%'.$path)
            ->whereNull('data->payment_id')
            ->get()
            ->each(function (DatabaseNotification $notification): void {
                $notification->update([
                    'data' => array_merge($notification->data, [
                        'payment_id' => $this->payment->id,
                        'invoice_id' => $this->invoice->id,
                    ]),
                ]);
            });
    }
}
