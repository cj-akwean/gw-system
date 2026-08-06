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
 * found by the tagged `data.payment_id`; a URL fallback covers notifications
 * created before tagging existed.
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

                foreach ($notifications as $notification) {
                    if (! empty($notification->data['resolved_at'])) {
                        return 'already';
                    }
                }

                (new SendPaymentConfirmationEmail($invoice, $payment))->handle();

                if ($notifications->isNotEmpty()) {
                    $now = now();
                    $recipientsText = implode(', ', $recipients);

                    foreach ($notifications as $notification) {
                        $notification->update([
                            'data' => array_merge($notification->data, [
                                'resolved_at' => $now->toISOString(),
                                'resend_count' => (int) ($notification->data['resend_count'] ?? 0) + 1,
                                'title' => 'Payment confirmation email resent',
                                'body' => 'Receipt resent to '.$recipientsText.' at '.$now->format('Y-m-d H:i:s').'.',
                                'color' => 'success',
                                'status' => 'success',
                                'actions' => [],
                            ]),
                        ]);
                    }
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
     * Notifications for this payment: tagged rows first, plus a URL fallback
     * so rows created before the payment_id tag (e.g. earlier dev data) are
     * still found and flipped to resolved.
     *
     * @return Builder<DatabaseNotification>
     */
    private function notificationsFor(Payment $payment): Builder
    {
        $url = route('admin.payments.resend-receipt', $payment);

        return DatabaseNotification::query()
            ->where('data->payment_id', (string) $payment->id)
            ->orWhere('data->actions->0->url', $url);
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
