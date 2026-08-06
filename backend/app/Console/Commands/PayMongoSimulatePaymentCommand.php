<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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
 */
class PayMongoSimulatePaymentCommand extends Command
{
    protected $signature = 'paymongo:simulate-payment {invoice? : Invoice ID or invoice number — defaults to the first unpaid/overdue invoice}
        {--source=card : PayMongo channel to record (card, gcash, qrph, ...)}
        {--payer-name= : Payer name from checkout billing — defaults to the first linked user, else "Test Payer"}
        {--payer-email= : Payer email — defaults to the first linked user, else test@example.com}
        {--payer-phone= : Payer phone — defaults to the first linked user}';

    protected $description = 'Locally simulates a payment.paid webhook for an unpaid invoice (no ngrok, no dashboard, no test card).';

    public function handle(): int
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

        $intentId = $invoice->paymongo_payment_intent_id;

        if ($intentId === null) {
            $intentId = 'pi_sim_'.Str::random(16);
            $invoice->update(['paymongo_payment_intent_id' => $intentId]);
            $this->warn("Invoice has no stored PayMongo intent — fabricated a simulation intent id ({$intentId}).");
        }

        $eventId = 'evt_sim_'.Str::random(16);
        $paymentId = 'pay_sim_'.Str::random(16);
        $payer = $this->payer();

        $payload = [
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => $paymentId,
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => (int) round((float) $invoice->total_amount * 100),
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => $intentId,
                            'paid_at' => now()->timestamp,
                            'source' => [
                                'id' => 'sim_src_'.Str::random(8),
                                'type' => $source,
                            ],
                            'billing' => $payer,
                        ],
                    ],
                ],
            ],
        ];

        (new ProcessPayMongoWebhook($payload))->handle();

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
        $this->info("  payment: {$paymentId}");
        $this->info("  event:   {$eventId}");
        $this->line('  payer:   '.implode(' · ', array_values(array_filter($payer))));
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
