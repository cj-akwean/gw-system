<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\ProcessedWebhookEvent;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes a verified PayMongo webhook event (dispatched by the webhook
 * controller's known-event branch).
 *
 * Dedupe is twofold: a processed_webhook_events row keyed on the unique event
 * id (covers PayMongo redeliveries/retries of the same event), and the invoice
 * status guard in PaymentService::markPaidFromWebhook (covers distinct events
 * for the same payment, e.g. payment.paid racing payment_intent.succeeded).
 *
 * For payment.paid the dedupe row is written ATOMICALLY with the state change
 * (one transaction) — a crash or DB failure in between rolls everything back,
 * so the job retry reprocesses cleanly instead of hitting the dedupe row and
 * silently dropping the event. A UniqueConstraintViolationException (another
 * delivery already processed this event) is caught at the outermost level: by
 * then the whole transaction has rolled back, which also keeps the Postgres
 * connection out of the aborted-transaction state.
 */
class ProcessPayMongoWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public array $payload,
    ) {}

    public function handle(): void
    {
        $data = $this->payload['data'] ?? null;

        if (! is_array($data)) {
            Log::channel('paymongo')->warning('ProcessPayMongoWebhook skipped: malformed payload', [
                'event_id' => $this->payload['data']['id'] ?? null,
            ]);

            return;
        }

        $eventId = $data['id'] ?? null;
        $type = $data['attributes']['type'] ?? null;

        if (! is_string($eventId) || ! is_string($type)) {
            Log::channel('paymongo')->warning('ProcessPayMongoWebhook skipped: missing event id or type');

            return;
        }

        if ($type !== 'payment.paid') {
            try {
                $this->recordProcessedEvent($eventId, $type);
            } catch (UniqueConstraintViolationException) {
                Log::channel('paymongo')->info('PayMongo webhook skipped: event already processed', [
                    'event_id' => $eventId,
                ]);

                return;
            }

            Log::channel('paymongo')->info('PayMongo webhook event processed (no state change)', [
                'event_id' => $eventId,
                'event_type' => $type,
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($eventId, $type, $data): void {
                $this->recordProcessedEvent($eventId, $type);

                $attributes = $data['attributes']['data']['attributes'] ?? [];
                $paymentId = $data['attributes']['data']['id'] ?? null;
                $intentId = $attributes['payment_intent_id'] ?? null;
                $amountCentavos = $attributes['amount'] ?? null;
                $paidAt = $attributes['paid_at'] ?? null;
                $paymongoSource = is_string($attributes['source']['type'] ?? null) ? $attributes['source']['type'] : null;

                if (! is_string($paymentId) || ! is_string($intentId) || ! is_int($amountCentavos)) {
                    Log::channel('paymongo')->warning('PayMongo payment.paid skipped: malformed payment resource', [
                        'event_id' => $eventId,
                        'payment_id' => $paymentId,
                        'intent_id' => $intentId,
                        'amount_is_int' => is_int($amountCentavos),
                    ]);

                    return;
                }

                $invoice = Invoice::query()->where('paymongo_payment_intent_id', $intentId)->first();

                if ($invoice === null) {
                    Log::channel('paymongo')->error('PayMongo payment.paid skipped: no invoice for intent', [
                        'event_id' => $eventId,
                        'payment_id' => $paymentId,
                        'intent_id' => $intentId,
                    ]);

                    return;
                }

                app(PaymentService::class)->markPaidFromWebhook(
                    $invoice,
                    $paymentId,
                    $amountCentavos,
                    is_int($paidAt) ? $paidAt : null,
                    $paymongoSource,
                );
            });
        } catch (UniqueConstraintViolationException) {
            Log::channel('paymongo')->info('PayMongo webhook skipped: event already processed', [
                'event_id' => $eventId,
            ]);
        }
    }

    /**
     * Records the event as processed. May throw UniqueConstraintViolationException
     * when the event id was already recorded — callers catch it at the
     * outermost transaction level, where the rollback has already completed.
     */
    private function recordProcessedEvent(string $eventId, string $type): void
    {
        // Wrapped in a transaction so a unique-violation on the insert rolls
        // back and recovers the Postgres connection BEFORE the caller's catch
        // runs — otherwise Postgres leaves the connection in an aborted state
        // (SQLSTATE 25P02) and the next query fails. The exception still
        // propagates for the caller to handle.
        DB::transaction(fn () => ProcessedWebhookEvent::create([
            'event_id' => $eventId,
            'event_type' => $type,
            'processed_at' => now(),
        ]));
    }
}
