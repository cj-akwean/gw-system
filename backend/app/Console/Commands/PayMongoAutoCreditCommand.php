<?php

namespace App\Console\Commands;

use App\Filament\Resources\PaymentResource;
use App\Models\Invoice;
use App\Services\PaymentService;
use App\Services\PayMongoService;
use App\Support\AdminNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Automatically credits invoices whose PayMongo payment intent succeeded
 * but the webhook was missed (wrong URL, ngrok down, etc.).
 *
 * Runs every 5 minutes via the scheduler. Uses the same
 * PaymentService::markPaidFromWebhook path as the webhook processor so
 * deduplication and email sending work identically.
 */
class PayMongoAutoCreditCommand extends Command
{
    protected $signature = 'paymongo:auto-credit';

    protected $description = 'Auto-credit invoices with succeeded PayMongo intents that were never credited by webhook.';

    public function handle(PayMongoService $payMongo, PaymentService $paymentService): int
    {
        $credited = 0;
        $failed = 0;

        Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereNotNull('paymongo_payment_intent_id')
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($payMongo, $paymentService, &$credited, &$failed): void {
                try {
                    $status = $payMongo->getPaymentIntentStatus($invoice->paymongo_payment_intent_id);

                    if ($status !== 'succeeded') {
                        return;
                    }

                    // Get full intent details for the payment id and amount.
                    $intentDetails = $payMongo->getPaymentIntent($invoice->paymongo_payment_intent_id);

                    $paymentId = $intentDetails['payment_id'] ?? 'pay_intended_'.$invoice->paymongo_payment_intent_id;
                    $amountCentavos = $intentDetails['amount'] ?? (int) round($invoice->total_amount * 100);

                    $payment = $paymentService->markPaidFromWebhook(
                        $invoice,
                        $paymentId,
                        $amountCentavos,
                        null,
                        $intentDetails['source'] ?? null,
                        null,
                        null,
                        null,
                    );

                    if ($payment !== null) {
                        $credited++;

                        $channel = PaymentResource::channelLabel($intentDetails['source'] ?? null);

                        AdminNotifier::notify(
                            'Payment credited (auto-reconciled)',
                            'Invoice '.$invoice->invoice_number.' — ₱'.number_format((float) $payment->amount, 2)
                                .($channel !== '—' ? ' via '.$channel : '').'. Credited automatically after webhook miss.',
                        );

                        Log::channel('paymongo')->info('auto-credit: invoice credited', [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'payment_id' => $paymentId,
                        ]);
                    }
                } catch (RuntimeException $e) {
                    $failed++;

                    Log::channel('paymongo')->warning('auto-credit: failed to check intent', [
                        'invoice_id' => $invoice->id,
                        'intent_id' => $invoice->paymongo_payment_intent_id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Rate-limit: 100ms between PayMongo API calls.
                usleep(100_000);
            });

        if ($credited === 0 && $failed === 0) {
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'paymongo:auto-credit completed — %d credited, %d failed.',
            $credited,
            $failed,
        ));

        return self::SUCCESS;
    }
}
