<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\Payment;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * One-click resend for the "payment confirmation email failed" admin
 * notification. Mirrors `paymongo:send-receipt`: sends synchronously so the
 * outcome is immediate and independent of queue-worker health.
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
            (new SendPaymentConfirmationEmail($invoice, $payment))->handle();
        } catch (Throwable $exception) {
            return $this->redirectWith('danger', 'Resend failed', $exception->getMessage());
        }

        return $this->redirectWith('success', 'Receipt sent', 'Receipt sent to: '.implode(', ', $recipients));
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
