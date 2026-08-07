<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\Payment;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-click resend for the "payment confirmation email failed" admin
 * notification. Mirrors `paymongo:send-receipt`: sends synchronously so the
 * outcome is immediate and independent of queue-worker health.
 *
 * Idempotent: once a receipt has been resent successfully, the linked
 * notification(s) are rewritten to a resolved state (success color, body,
 * no button) and further clicks only warn — no duplicate emails. A row lock
 * held across check + send + resolve serializes concurrent clicks. Rows are
 * found by the tagged `data.payment_id`; a path-suffix fallback covers
 * notifications created before tagging existed, regardless of which host
 * their stored action URL points at (APP_URL changes orphaned rows that were
 * matched by absolute-URL equality).
 */
class ResendReceiptController extends Controller
{
    public function __invoke(Payment $payment): RedirectResponse
    {
        $invoice = $payment->invoice;

        if ($invoice === null) {
            return $this->redirectWith(
                'warning',
                'Receipt not sent',
                'Payment #'.$payment->id.' has no invoice — receipt skipped.',
            );
        }

        $recipients = SendPaymentConfirmationEmail::recipientsFor($invoice);

        if ($recipients === []) {
            return $this->redirectWith(
                'warning',
                'Receipt not sent',
                'No linked users with a valid email — receipt skipped (payment is unaffected).',
            );
        }

        try {
            $state = DB::transaction(function () use ($payment, $invoice, $recipients): string {
                $notifications = $this->notificationsFor($payment)->lockForUpdate()->get();

                $pending = $notifications->filter(
                    fn (DatabaseNotification $notification): bool => empty($notification->data['resolved_at'])
                );

                if ($notifications->isNotEmpty() && $pending->isEmpty()) {
                    return 'already';
                }

                (new SendPaymentConfirmationEmail($invoice, $payment))->handle();

                $now = now();
                $recipientsText = implode(', ', $recipients);

                foreach ($pending as $notification) {
                    $notification->update([
                        'data' => array_merge($notification->data, [
                            'resolved_at' => $now->toISOString(),
                            'resend_count' => (int) ($notification->data['resend_count'] ?? 0) + 1,
                            'payment_id' => (string) $payment->id,
                            'invoice_id' => (string) $invoice->id,
                            'title' => 'Payment confirmation email resent',
                            'body' => 'Receipt resent to '.$recipientsText.' at '.$now->format('Y-m-d H:i:s').'.',
                            'color' => 'success',
                            'status' => 'success',
                            'actions' => [],
                        ]),
                    ]);
                }

                return 'resent';
            });
        } catch (Throwable $exception) {
            return $this->redirectWith('danger', 'Resend failed', $exception->getMessage());
        }

        if ($state === 'already') {
            return $this->redirectWith(
                'info',
                'Receipt already resent',
                'This receipt was already resent — no duplicate email was sent.',
            );
        }

        return $this->redirectWith('success', 'Receipt sent', 'Receipt sent to: '.implode(', ', $recipients));
    }

    /**
     * Failure notifications for this payment: tagged rows (`data.payment_id`)
     * first, plus a path-suffix fallback so legacy rows created before the tag
     * (or under a different APP_URL host) are still found and flipped to
     * resolved. Only Filament-format rows are eligible — scoped so a row that
     * merely shares a payment id in unrelated payloads is never rewritten.
     *
     * @return Builder<DatabaseNotification>
     */
    private function notificationsFor(Payment $payment): Builder
    {
        $path = (string) parse_url(route('admin.payments.resend-receipt', $payment), PHP_URL_PATH);

        return DatabaseNotification::query()
            ->where('data->format', 'filament')
            ->where(function (Builder $query) use ($payment, $path): void {
                $query
                    ->where('data->payment_id', (string) $payment->id)
                    ->orWhere('data->actions->0->url', 'like', '%'.$path);
            });
    }

    protected function redirectWith(string $color, string $title, string $body): RedirectResponse
    {
        Notification::make()
            ->status($color)
            ->title($title)
            ->body($body)
            ->send();

        return redirect()->route('filament.admin.pages.dashboard');
    }
}
