<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Services\PaymentSimulationService;
use Illuminate\Console\Command;

/**
 * Local webhook simulator for manual testing — fires the same payment.paid
 * payload through the same ProcessPayMongoWebhook job a real PayMongo
 * delivery would dispatch, but without ngrok, the PayMongo dashboard, or a
 * test card transaction.
 *
 * Marks the invoice paid, records the Payment row, and queues the
 * confirmation email exactly like a real webhook — so a failed Mailtrap host
 * still produces the admin bell notification + "Resend receipt" button. It
 * deliberately does NOT exercise PayMongo's signature verification or the
 * cutomer-facing checkout (use the ngrok + pay-checkout.html recipe for those).
 *
 * The payload build + dispatch live in PaymentSimulationService so the
 * dev-only POST /api/dev/payments/simulate endpoint can reuse them (a browser
 * button cannot invoke a CLI).
 */
class PayMongoSimulatePaymentCommand extends Command
{
    protected $signature = 'paymongo:simulate-payment {invoice? : Invoice ID or invoice number — defaults to the first unpaid/overdue invoice}
        {--source=card : PayMongo channel to record (card, gcash, qrph, ...)}
        {--payer-name= : Payer name from checkout billing — defaults to the first linked user, else "Test Payer"}
        {--payer-email= : Payer email — defaults to the first linked user, else test@example.com}
        {--payer-phone= : Payer phone — defaults to the first linked user}';

    protected $description = 'Locally simulates a payment.paid webhook for an unpaid invoice (no ngrok, no dashboard, no test card).';

    public function handle(PaymentSimulationService $simulation): int
    {
        $invoice = $this->resolveInvoice($this->argument('invoice'));

        if ($invoice === null) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            $this->error("Invoice {$invoice->invoice_number} is not payable (status: {$invoice->status}).");

            return self::FAILURE;
        }

        $source = $this->option('source');

        if (mb_strlen($source) > 30) {
            $this->error('Source channel must be 30 characters or fewer (column limit).');

            return self::FAILURE;
        }

        $hadIntent = $invoice->paymongo_payment_intent_id !== null;

        $result = $simulation->simulate($invoice, $source, $this->payer());

        if (! $hadIntent) {
            $this->warn('Invoice has no stored PayMongo intent — fabricated a simulation intent id ('.$invoice->fresh()->paymongo_payment_intent_id.').');
        }

        $invoice->refresh();

        if ($invoice->status !== 'paid') {
            $this->error("The webhook job ran but invoice {$invoice->invoice_number} is still {$invoice->status} — check storage/logs/paymongo.log for the skip reason.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Simulated payment.paid → invoice %s marked paid (₱%s, %s)',
            $invoice->invoice_number,
            number_format((float) $invoice->total_amount, 2),
            $source,
        ));
        $this->info("  payment: {$result['payment_id']}");
        $this->info("  event:   {$result['event_id']}");
        $this->line('  payer:   '.implode(' · ', array_values(array_filter($result['payer']))));
        $this->line('Confirmation email queued — run php artisan queue:work --tries=3; a failed delivery triggers the admin bell "Resend receipt" button.');
        $this->line('Note: bypasses PayMongo signature verification and the checkout itself — use ngrok + pay-checkout.html to cover those.');

        return self::SUCCESS;
    }

    private function resolveInvoice(?string $value): ?Invoice
    {
        if ($value === null || $value === '') {
            return Invoice::query()
                ->whereIn('status', ['unpaid', 'overdue'])
                ->orderBy('id')
                ->first();
        }

        return is_numeric($value)
            ? Invoice::query()->find((int) $value)
            : Invoice::query()->where('invoice_number', $value)->first();
    }

    /**
     * Payer billing object from the command options, falling back to the
     * first linked portal user (so the receipt shows a real recipient).
     */
    private function payer(): array
    {
        $user = User::query()
            ->whereHas('connectionLinks', fn ($q) => $q->where('status', 'active')->whereNull('unlinked_at'))
            ->orderBy('id')
            ->first();

        return [
            'name' => $this->option('payer-name') ?: $user?->name ?: 'Test Payer',
            'email' => $this->option('payer-email') ?: $user?->email ?: 'test@example.com',
            'phone' => $this->option('payer-phone') ?: $user?->phone,
        ];
    }
}