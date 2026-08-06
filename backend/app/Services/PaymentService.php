<?php

namespace App\Services;

use App\Exceptions\InvoiceNotPayableException;
use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentService
{
    public const OFFLINE_METHODS = ['cash'];

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
     * @param  string|null  $paymongoSource  payment channel used, e.g. gcash / card (source.type from the event)
     */
    public function markPaidFromWebhook(
        Invoice $invoice,
        string $paymentId,
        int $amountCentavos,
        ?int $paidAt = null,
        ?string $paymongoSource = null,
    ): ?Payment {
        return DB::transaction(function () use ($invoice, $paymentId, $amountCentavos, $paidAt, $paymongoSource): ?Payment {
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
                'paymongo_source' => $paymongoSource,
                'paid_at' => $paidAt !== null ? Carbon::createFromTimestamp($paidAt, config('app.timezone')) : now(),
            ]);

            // Only dispatched when a payment row was actually created, and only
            // after the outer transaction commits — the email job must never
            // render against an uncommitted invoice/payment.
            SendPaymentConfirmationEmail::dispatch($locked, $payment)->afterCommit();

            Log::channel('paymongo')->info('PayMongo webhook processed: invoice marked paid', [
                'invoice_id' => $locked->id,
                'payment_id' => $paymentId,
                'amount_centavos' => $amountCentavos,
            ]);

            return $payment;
        });
    }

    /**
     * Records a manual/offline payment (e.g. cash collected over the counter)
     * and marks the invoice paid. Atomic: locks the invoice row and only ever
     * transitions {unpaid, overdue} -> paid. Never touches the PayMongo
     * reference namespace — offline rows keep paymongo_reference NULL. No
     * receipt email is sent (the customer paid the paper bill at the office).
     *
     * Real cash does not split centavos, so the amount only needs to be within
     * one peso of the invoice total; the recorded amount is what was received.
     *
     * @param  float  $amount  amount actually received (within ₱1.00 of the total)
     *
     * @throws InvoiceNotPayableException invoice missing, already paid, or not {unpaid, overdue}
     * @throws InvalidArgumentException amount non-positive/zero, out of tolerance, or method not allowed
     */
    public function recordOfflinePayment(
        int $invoiceId,
        float $amount,
        ?string $reference = null,
        ?string $paidAt = null,
        ?int $recordedBy = null,
        string $method = 'cash',
    ): Payment {
        if (! in_array($method, self::OFFLINE_METHODS, true)) {
            throw new InvalidArgumentException(sprintf('Payment method "%s" is not an offline payment method.', $method));
        }

        if ($reference !== null && mb_strlen($reference) > 100) {
            throw new InvalidArgumentException('Payment reference must be 100 characters or fewer.');
        }

        $paidAtParsed = null;

        if ($paidAt !== null && $paidAt !== '') {
            try {
                $paidAtParsed = Carbon::parse($paidAt);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('Payment date is not a valid date.');
            }

            if ($paidAtParsed->toDateString() > now()->toDateString()) {
                throw new InvalidArgumentException('Payment date cannot be in the future.');
            }
        }

        return DB::transaction(function () use ($invoiceId, $amount, $reference, $paidAtParsed, $recordedBy, $method): Payment {
            /** @var Invoice|null $locked */
            $locked = Invoice::query()->lockForUpdate()->find($invoiceId);

            if ($locked === null) {
                throw new InvalidArgumentException('Invoice not found or no longer exists.');
            }

            if (! in_array($locked->status, ['unpaid', 'overdue'], true)) {
                throw new InvoiceNotPayableException($locked);
            }

            if ($amount <= 0) {
                throw new InvalidArgumentException('Payment amount must be a positive number.');
            }

            if (abs($amount - (float) $locked->total_amount) >= 1.00) {
                throw new InvalidArgumentException(sprintf(
                    'Offline payments must be within ₱1.00 of the invoice total (₱%s). Entered ₱%s.',
                    number_format((float) $locked->total_amount, 2),
                    number_format($amount, 2),
                ));
            }

            $locked->update(['status' => 'paid']);

            if ($locked->paymongo_payment_intent_id !== null) {
                Log::channel('paymongo')->warning(
                    'Offline payment recorded on an invoice with a stored PayMongo intent — verify with the customer / dashboard that no online payment also went through (double-collection watch).',
                    [
                        'invoice_id' => $locked->id,
                        'intent_id' => $locked->paymongo_payment_intent_id,
                    ]
                );
            }

            return Payment::create([
                'invoice_id' => $locked->id,
                'amount' => round($amount, 2),
                'method' => $method,
                'paymongo_reference' => null,
                'reference' => $reference ?: null,
                'paid_at' => $paidAtParsed ?? now(),
                'recorded_by' => $recordedBy,
            ]);
        });
    }
}
