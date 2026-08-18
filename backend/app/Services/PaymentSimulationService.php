<?php

namespace App\Services;

use App\Exceptions\InvoiceNotPayableException;
use App\Jobs\ProcessPayMongoWebhook;
use App\Models\Invoice;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Builds a payment.paid webhook payload for an unpaid invoice and dispatches it
 * through the real ProcessPayMongoWebhook job synchronously — the shared engine
 * behind both the `paymongo:simulate-payment` CLI and the dev-only
 * `POST /api/dev/payments/simulate` harness (the browser cannot invoke a CLI).
 *
 * Deliberately DOES NOT exercise PayMongo's signature verification or the
 * checkout — it starts downstream of intent creation, exactly like the QR Ph
 * simulator only covers "after the code was scanned".
 */
class PaymentSimulationService
{
    /**
     * @return array{payment_id: string, event_id: string, payer: array{name: string, email: string, phone: ?string}}
     */
    public function simulate(Invoice $invoice, string $source = 'card', ?array $payer = null, bool $forceFreshIntent = false): array
    {
        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            throw new InvoiceNotPayableException($invoice);
        }

        if (mb_strlen($source) > 30) {
            throw new InvalidArgumentException('Source channel must be 30 characters or fewer (column limit).');
        }

        // The CLI deliberately reuses a stored intent when present. The
        // browser harness (forceFreshIntent) must NEVER reuse one: the stored
        // intent is typically a leftover from another flow (e.g. an expired
        // QR Ph code), and attributing a simulated Google Pay payment to it
        // makes the payment look like it came through that flow. The
        // simulation always gets its own pi_sim_ intent instead.
        $intentId = $forceFreshIntent ? null : $invoice->paymongo_payment_intent_id;

        if ($intentId === null) {
            $intentId = 'pi_sim_'.Str::random(16);
            $invoice->update(['paymongo_payment_intent_id' => $intentId]);
        }

        $eventId = 'evt_sim_'.Str::random(16);
        $paymentId = 'pay_sim_'.Str::random(16);
        $payer ??= $this->firstLinkedPayer();
        $now = time();

        // Structurally identical to a real payment.paid delivery (docs →
        // developer-tools-webhooks-events): event envelope + payment resource
        // with source.type = google_pay_card for the harness. Only the fields
        // ProcessPayMongoWebhook reads drive behavior; the rest make the
        // payload comparable to the dashboard's "Payload" view.
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
                            'access_url' => null,
                            'amount' => (int) round((float) $invoice->total_amount * 100),
                            'balance_transaction_id' => 'bal_txn_sim_'.Str::random(12),
                            'billing' => $payer,
                            'currency' => 'PHP',
                            'description' => 'Invoice '.$invoice->invoice_number,
                            'disputed' => false,
                            'fee' => 0,
                            'foreign_fee' => 0,
                            'livemode' => false,
                            'net_amount' => (int) round((float) $invoice->total_amount * 100),
                            'origin' => 'api',
                            'payment_intent_id' => $intentId,
                            'payout' => null,
                            'source' => [
                                'id' => 'google_pay_card_sim_'.Str::random(8),
                                'type' => $source,
                                'brand' => 'visa',
                                'last4' => '4242',
                                'country' => 'PH',
                            ],
                            'statement_descriptor' => 'Guinobatan Waterworks',
                            'status' => 'paid',
                            'tax_amount' => 0,
                            'refunds' => [],
                            'taxes' => [],
                            'available_at' => $now + (3 * 86400),
                            'created_at' => $now,
                            'paid_at' => $now,
                            'updated_at' => $now,
                        ],
                    ],
                    'previous_data' => [],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ],
        ];

        // Synchronous on purpose: the polling UI needs a deterministic outcome
        // in the same request; only the confirmation email is queued (by the
        // job itself).
        (new ProcessPayMongoWebhook($payload))->handle();

        return [
            'payment_id' => $paymentId,
            'event_id' => $eventId,
            'payer' => $payer,
        ];
    }

    /**
     * Payer billing object defaulting to the first linked portal user (so the
     * receipt shows a real recipient), matching the CLI fallback.
     *
     * @return array{name: string, email: string, phone: ?string}
     */
    protected function firstLinkedPayer(): array
    {
        $user = User::query()
            ->whereHas('connectionLinks', fn ($q) => $q->where('status', 'active')->whereNull('unlinked_at'))
            ->orderBy('id')
            ->first();

        return [
            'name' => $user?->name ?: 'Test Payer',
            'email' => $user?->email ?: 'test@example.com',
            'phone' => $user?->phone,
        ];
    }
}