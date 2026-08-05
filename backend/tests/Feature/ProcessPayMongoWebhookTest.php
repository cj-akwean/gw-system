<?php

namespace Tests\Feature;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessPayMongoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceFor(string $intentId, string $status = 'unpaid', float $total = 40.00): Invoice
    {
        return Invoice::factory()->create([
            'paymongo_payment_intent_id' => $intentId,
            'status' => $status,
            'total_amount' => $total,
        ]);
    }

    private function paymentPaidPayload(array $overrides = []): array
    {
        return array_merge_recursive([
            'data' => [
                'id' => 'evt_paid_1',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_res_1',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_test_1',
                            'paid_at' => 1619426488,
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function runJob(array $payload): void
    {
        (new ProcessPayMongoWebhook($payload))->handle();
    }

    public function test_payment_paid_marks_invoice_paid_and_records_payment(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob($this->paymentPaidPayload());

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
        ]);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();

        $this->assertSame('paymongo', $payment->method);
        $this->assertSame('pay_res_1', $payment->paymongo_reference);
        $this->assertSame(40.0, (float) $payment->amount);
        $this->assertSame(1619426488, $payment->paid_at->timestamp);
    }

    public function test_an_already_paid_invoice_is_left_alone(): void
    {
        $this->invoiceFor('pi_test_1', 'paid', 40.00);
        $this->assertDatabaseCount('payments', 0);

        $this->runJob($this->paymentPaidPayload());

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('paid', Invoice::where('paymongo_payment_intent_id', 'pi_test_1')->first()->status);
    }

    public function test_an_overdue_invoice_is_marked_paid(): void
    {
        $invoice = $this->invoiceFor('pi_test_1', 'overdue', 40.00);

        $this->runJob($this->paymentPaidPayload());

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_duplicate_event_id_is_processed_only_once(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob($this->paymentPaidPayload());
        $this->runJob($this->paymentPaidPayload());

        $this->assertDatabaseCount('processed_webhook_events', 1);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_payment_failed_event_is_logged_only(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob([
            'data' => [
                'id' => 'evt_failed_1',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_fail_1',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'failed',
                            'payment_intent_id' => 'pi_test_1',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('processed_webhook_events', [
            'event_id' => 'evt_failed_1',
            'event_type' => 'payment.failed',
        ]);
    }

    public function test_a_duplicate_payment_failed_event_is_skipped_not_failed(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $payload = [
            'data' => [
                'id' => 'evt_failed_dup',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_fail_dup',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'failed',
                            'payment_intent_id' => 'pi_test_1',
                        ],
                    ],
                ],
            ],
        ];

        $this->runJob($payload);
        $this->runJob($payload);

        $this->assertDatabaseCount('processed_webhook_events', 1);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_duplicate_intent_succeeded_event_is_skipped_not_failed(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $payload = [
            'data' => [
                'id' => 'evt_intent_dup',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment_intent.succeeded',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pi_test_1',
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ];

        $this->runJob($payload);
        $this->runJob($payload);

        $this->assertDatabaseCount('processed_webhook_events', 1);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_non_paid_duplicate_delivered_after_payment_paid_changes_nothing(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob($this->paymentPaidPayload());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());

        $failed = [
            'data' => [
                'id' => 'evt_failed_after',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.failed',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_fail_after',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'failed',
                            'payment_intent_id' => 'pi_test_1',
                        ],
                    ],
                ],
            ],
        ];

        $this->runJob($failed);
        $this->runJob($failed);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseCount('processed_webhook_events', 2);
    }

    public function test_payment_intent_succeeded_event_is_logged_only(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob([
            'data' => [
                'id' => 'evt_intent_succeeded_1',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment_intent.succeeded',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pi_test_1',
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_unknown_intent_is_logged_without_state_change(): void
    {
        $this->invoiceFor('pi_test_1');

        $payload = $this->paymentPaidPayload();
        $payload['data']['attributes']['data']['attributes']['payment_intent_id'] = 'pi_unknown';

        $this->runJob($payload);

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('unpaid', Invoice::first()->status);
    }

    public function test_an_amount_mismatch_skips_state_change(): void
    {
        $invoice = $this->invoiceFor('pi_test_1', 'unpaid', 50.00);

        $this->runJob($this->paymentPaidPayload());

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_malformed_payment_paid_event_is_logged_without_state_change(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $payload = $this->paymentPaidPayload();
        $payload['data']['attributes']['data']['attributes']['amount'] = 'optionally-a-string';
        unset($payload['data']['attributes']['data']['attributes']['paid_at']);

        $this->runJob($payload);

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }

    public function test_a_payload_without_data_is_logged_and_acknowledged(): void
    {
        $this->invoiceFor('pi_test_1');

        $this->runJob([]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    public function test_a_failure_after_dedupe_rolls_back_and_the_retry_marks_paid(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $paymentService = $this->createMock(PaymentService::class);
        $paymentService->expects($this->once())
            ->method('markPaidFromWebhook')
            ->with(
                $this->isInstanceOf(Invoice::class),
                'pay_res_1',
                4000,
                1619426488,
            )
            ->willThrowException(new RuntimeException('simulated failure'));

        $this->app->instance(PaymentService::class, $paymentService);

        try {
            $this->runJob($this->paymentPaidPayload());
            $this->fail('Expected RuntimeException from the first attempt.');
        } catch (RuntimeException) {
            // expected — a real failure would fail the job and the queue retries it
        }

        // The dedupe row must have rolled back with the failure, or the retry
        // would hit the unique index and silently drop the event.
        $this->assertDatabaseCount('processed_webhook_events', 0);
        $this->assertSame('unpaid', $invoice->fresh()->status);

        $this->app->forgetInstance(PaymentService::class);

        $this->runJob($this->paymentPaidPayload());

        $this->assertDatabaseCount('processed_webhook_events', 1);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_intent_succeeded_then_payment_paid_processes_both_events(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $this->runJob([
            'data' => [
                'id' => 'evt_intent_succeeded_seq',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment_intent.succeeded',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pi_test_1',
                        'type' => 'payment_intent',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);

        $payload = $this->paymentPaidPayload();
        $payload['data']['id'] = 'evt_paid_seq';

        $this->runJob($payload);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseCount('processed_webhook_events', 2);
    }

    public function test_paid_at_falls_back_to_now_when_event_has_no_timestamp(): void
    {
        $invoice = $this->invoiceFor('pi_test_1');

        $payload = $this->paymentPaidPayload();
        unset($payload['data']['attributes']['data']['attributes']['paid_at']);

        $this->runJob($payload);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertLessThan(2, $payment->paid_at->diffInMinutes(now()));
    }
}
