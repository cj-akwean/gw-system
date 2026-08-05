<?php

namespace App\Console\Commands;

use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Throwable;

class PayMongoSendReceiptCommand extends Command
{
    protected $signature = 'paymongo:send-receipt {invoice : Invoice ID}';

    protected $description = 'Re-sends the payment confirmation email (with the invoice PDF) for a paid invoice after a previous delivery failed.';

    public function handle(): int
    {
        $invoice = Invoice::query()->find($this->argument('invoice'));

        if ($invoice === null) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        $payment = $invoice->payments()->latest('id')->first();

        if ($payment === null) {
            $this->error("Invoice {$invoice->invoice_number} has no recorded payment — nothing to send.");

            return self::FAILURE;
        }

        $recipients = SendPaymentConfirmationEmail::recipientsFor($invoice);

        if ($recipients === []) {
            $this->warn('No linked users with a valid email — receipt skipped (payment is unaffected).');

            return self::SUCCESS;
        }

        try {
            (new SendPaymentConfirmationEmail($invoice, $payment))->handle();
        } catch (Throwable $exception) {
            $this->error('Sending failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Receipt sent to: '.implode(', ', $recipients));

        return self::SUCCESS;
    }
}
