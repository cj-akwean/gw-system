<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PayMongoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Detects PayMongo charges that were never credited locally.
 *
 * Leg A: unpaid/overdue invoices whose stored payment intent already
 * succeeded — the payment.paid webhook was missed or is still in flight;
 * /pay is blocked for these (PaymentAlreadyCompletedException), so the only
 * way out is a manual credit.
 *
 * Leg B: paid PayMongo payments (recent window) with no local Payment row —
 * includes charges whose intent no longer points at any invoice ("no invoice
 * for intent" log lines).
 *
 * Read-only: never marks anything paid, never mutates state. Money-critical
 * flows stay manual.
 */
class PayMongoReconcileCommand extends Command
{
    protected $signature = 'paymongo:reconcile {--days=7 : How far back (in days) to scan PayMongo payments for charges with no local record}';

    protected $description = 'Find PayMongo charges that were never credited locally (invoice unpaid with a succeeded intent, or a paid payment with no Payment row). Read-only.';

    public function handle(PayMongoService $payMongo): int
    {
        $findings = [];
        $unchecked = [];

        $this->info('Checking invoices with a stored payment intent...');

        Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNotNull('paymongo_payment_intent_id')
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($payMongo, &$findings, &$unchecked): void {
                try {
                    $status = $payMongo->getPaymentIntentStatus($invoice->paymongo_payment_intent_id);

                    if ($status === 'succeeded') {
                        $findings[] = sprintf(
                            'CHARGED BUT NOT CREDITED | invoice %s (id %d) | intent %s | total PHP %s | /pay is blocked until credited',
                            $invoice->invoice_number,
                            $invoice->id,
                            $invoice->paymongo_payment_intent_id,
                            number_format((float) $invoice->total_amount, 2),
                        );
                    }
                } catch (RuntimeException $e) {
                    $unchecked[] = sprintf(
                        'UNCHECKED | invoice %s (id %d) | intent %s | %s',
                        $invoice->invoice_number,
                        $invoice->id,
                        $invoice->paymongo_payment_intent_id,
                        $e->getMessage(),
                    );
                }

                usleep(100_000);
            });

        $this->info('Checking recent paid PayMongo payments against local records...');

        $localReferences = Payment::query()
            ->where('method', 'paymongo')
            ->whereNotNull('paymongo_reference')
            ->pluck('paymongo_reference');

        try {
            $from = now()->subDays(max(1, (int) $this->option('days')))->startOfDay()->timestamp;
            $to = now()->timestamp;

            foreach ($payMongo->listPaidPayments($from, $to) as $payment) {
                if ($payment['id'] !== null && ! $localReferences->contains($payment['id'])) {
                    $findings[] = sprintf(
                        'PAYMENT WITHOUT LOCAL RECORD | payment %s | intent %s | amount PHP %s | status %s — credit manually or refund via dashboard',
                        $payment['id'],
                        $payment['payment_intent_id'] ?? 'n/a',
                        $payment['amount'] !== null ? number_format((int) $payment['amount'] / 100, 2) : 'n/a',
                        $payment['status'] ?? 'n/a',
                    );
                }
            }
        } catch (RuntimeException $e) {
            $unchecked[] = 'UNCHECKED | PayMongo payment list could not be retrieved | '.$e->getMessage();
        }

        foreach ($findings as $finding) {
            $this->error($finding);
            Log::channel('paymongo')->error('reconcile: '.$finding);
        }

        foreach ($unchecked as $uncheckable) {
            $this->warn($uncheckable);
            Log::channel('paymongo')->warning('reconcile: '.$uncheckable);
        }

        if ($findings === [] && $unchecked === []) {
            $this->info('paymongo:reconcile OK — no discrepancies found.');
            Log::channel('paymongo')->info('reconcile: OK — no discrepancies found');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'paymongo:reconcile completed — %d finding(s), %d uncheckable. Fix money issues manually (read-only command).',
            count($findings),
            count($unchecked),
        ));

        return self::FAILURE;
    }
}
