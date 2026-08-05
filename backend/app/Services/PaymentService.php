<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Marks an invoice paid from a PayMongo webhook event and records the
     * payment. Runs atomically: locks the invoice row, refuses any change
     * once already paid, and only ever transitions {unpaid, overdue} -> paid.
     *
     * Returns the new Payment row, or null when nothing was recorded
     * (already paid, non-payable status, amount mismatch).
     *
     * @param  string  $paymentId  PayMongo payment id (pay_...)
     * @param  int  $amountCentavos  amount from the webhook, in centavos
     * @param  int|null  $paidAt  unix timestamp from the event, when present
     */
    public function markPaidFromWebhook(
        Invoice $invoice,
        string $paymentId,
        int $amountCentavos,
        ?int $paidAt = null,
    ): ?Payment {
        return DB::transaction(function () use ($invoice, $paymentId, $amountCentavos, $paidAt): ?Payment {
            /** @var Invoice|null $locked */
            $locked = Invoice::query()->lockForUpdate()->find($invoice->id);

            if ($locked === null) {
                Log::channel('paymongo')->error('PaymentService: invoice gone before processing', [
                    'invoice_id' => $invoice->id,
                    'payment_id' => $paymentId,
                ]);

                return null;
            }

            if ($locked->status === 'paid') {
                Log::channel('paymongo')->info('PayMongo webhook skipped: invoice already paid', [
                    'invoice_id' => $locked->id,
                    'payment_id' => $paymentId,
                ]);

                return null;
            }

            if (! in_array($locked->status, ['unpaid', 'overdue'], true)) {
                Log::channel('paymongo')->warning('PayMongo webhook skipped: invoice not payable', [
                    'invoice_id' => $locked->id,
                    'payment_id' => $paymentId,
                    'status' => $locked->status,
                ]);

                return null;
            }

            $expectedCentavos = (int) round($locked->total_amount * 100);

            if ($amountCentavos !== $expectedCentavos) {
                Log::channel('paymongo')->error('PayMongo webhook skipped: amount mismatch', [
                    'invoice_id' => $locked->id,
                    'payment_id' => $paymentId,
                    'event_amount_centavos' => $amountCentavos,
                    'invoice_amount_centavos' => $expectedCentavos,
                ]);

                return null;
            }

            $locked->update(['status' => 'paid']);

            $payment = Payment::create([
                'invoice_id' => $locked->id,
                'amount' => round($amountCentavos / 100, 2),
                'method' => 'paymongo',
                'paymongo_reference' => $paymentId,
                'paid_at' => $paidAt !== null ? Carbon::createFromTimestamp($paidAt, config('app.timezone')) : now(),
            ]);

            Log::channel('paymongo')->info('PayMongo webhook processed: invoice marked paid', [
                'invoice_id' => $locked->id,
                'payment_id' => $paymentId,
                'amount_centavos' => $amountCentavos,
            ]);

            return $payment;
        });
    }
}
