<?php

namespace App\Console\Commands;

use App\Exceptions\InvoiceNotPayableException;
use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RecordOfflinePaymentCommand extends Command
{
    protected $signature = 'payments:record {invoice : Invoice ID or invoice number}
        {amount? : Amount received in pesos — defaults to the nearest peso of the invoice total}
        {--method=cash : Offline payment method}
        {--reference= : OR / reference number}
        {--paid-at= : Payment date (YYYY-MM-DD) — defaults to now}
        {--recorded-by= : ID of the user who collected the payment}';

    protected $description = 'Records an offline (over-the-counter) cash payment and marks the invoice paid.';

    public function handle(): int
    {
        $invoice = $this->resolveInvoice($this->argument('invoice'));

        if ($invoice === null) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        $recordedBy = $this->resolveRecordedBy($this->option('recorded-by'));

        if ($recordedBy === null && $this->option('recorded-by')) {
            $this->error('Recorded-by user not found.');

            return self::FAILURE;
        }

        $amount = $this->argument('amount') !== null
            ? (float) $this->argument('amount')
            : round((float) $invoice->total_amount);

        try {
            $payment = app(PaymentService::class)->recordOfflinePayment(
                invoiceId: $invoice->id,
                amount: $amount,
                reference: $this->option('reference') ?: null,
                paidAt: $this->option('paid-at') ?: null,
                recordedBy: $recordedBy,
                method: $this->option('method') ?: 'cash',
            );
        } catch (InvoiceNotPayableException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($amount !== (float) $invoice->total_amount) {
            $this->warn(sprintf(
                'Amount ₱%s differs from the invoice total ₱%s — within the ₱1.00 tolerance, recorded as ₱%s.',
                number_format($amount, 2),
                number_format((float) $invoice->total_amount, 2),
                number_format((float) $payment->amount, 2),
            ));
        }

        $this->info(sprintf(
            'Recorded %s payment — invoice %s, ₱%s, %s, %s',
            $payment->method,
            $invoice->invoice_number,
            number_format((float) $payment->amount, 2),
            $payment->reference ?: 'no reference',
            $payment->paid_at?->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function resolveInvoice(string $value): ?Invoice
    {
        return is_numeric($value)
            ? Invoice::query()->find((int) $value)
            : Invoice::query()->where('invoice_number', $value)->first();
    }

    private function resolveRecordedBy(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return User::query()->whereKey((int) $value)->exists() ? (int) $value : null;
    }
}
