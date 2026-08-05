<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceNotPayableException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Models\Invoice;
use App\Services\PayMongoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PayMongoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sk_test_dummy_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paymongo.secret_key', self::SECRET_KEY);
    }

    private function makeUnpaidInvoice(float $total = 2020.00): Invoice
    {
        return Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => $total,
        ]);
    }

    public function test_create_payment_intent_sends_expected_request_and_returns_intent_data(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_1',
                    'type' => 'payment_intent',
                    'attributes' => [
                        'status' => 'awaiting_payment_method',
                        'client_key' => 'pi_test_intent_1_client_key',
                    ],
                ],
            ]),
        ]);

        $invoice = $this->makeUnpaidInvoice();

        $result = app(PayMongoService::class)->createPaymentIntent($invoice);

        $this->assertSame('pi_test_intent_1', $result['intent_id']);
        $this->assertSame('pi_test_intent_1_client_key', $result['client_key']);
        $this->assertSame('pi_test_intent_1', $invoice->fresh()->paymongo_payment_intent_id);

        Http::assertSent(function (Request $request) use ($invoice) {
            if ($request->url() !== PayMongoService::API_BASE.'/payment_intents') {
                return false;
            }
            if ($request->method() !== 'POST') {
                return false;
            }
            if ($request->header('Authorization')[0] !== 'Basic '.base64_encode(self::SECRET_KEY.':')) {
                return false;
            }
            if ($request->header('Idempotency-Key')[0] !== 'invoice-pay-'.$invoice->id) {
                return false;
            }

            $attributes = $request['data']['attributes'];

            return $attributes['amount'] === 202000
                && $attributes['currency'] === 'PHP'
                && $attributes['payment_method_allowed'] === ['qrph', 'gcash', 'card']
                && $attributes['description'] === 'Invoice '.$invoice->invoice_number
                && $attributes['metadata'] === [
                    'invoice_id' => (string) $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ];
        });
    }

    public function test_create_payment_intent_converts_amount_to_centavos(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_2',
                    'attributes' => ['client_key' => 'pi_test_intent_2_client_key'],
                ],
            ]),
        ]);

        $invoice = $this->makeUnpaidInvoice(2020.50);

        app(PayMongoService::class)->createPaymentIntent($invoice);

        Http::assertSent(fn (Request $request) => $request['data']['attributes']['amount'] === 202050);
    }

    public function test_create_payment_intent_accepts_custom_methods(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_3',
                    'attributes' => ['client_key' => 'pi_test_intent_3_client_key'],
                ],
            ]),
        ]);

        $invoice = $this->makeUnpaidInvoice();

        app(PayMongoService::class)->createPaymentIntent($invoice, ['qrph']);

        Http::assertSent(fn (Request $request) => $request['data']['attributes']['payment_method_allowed'] === ['qrph']);
    }

    public function test_create_payment_intent_throws_when_api_returns_an_error(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'errors' => [['detail' => 'amount is invalid']],
            ], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayMongo create payment intent failed');

        app(PayMongoService::class)->createPaymentIntent($this->makeUnpaidInvoice());
    }

    public function test_create_payment_intent_throws_when_response_is_malformed(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response(['data' => ['id' => 'pi_test_intent_4']]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing payment intent id or client key');

        app(PayMongoService::class)->createPaymentIntent($this->makeUnpaidInvoice());
    }

    public function test_create_payment_intent_throws_when_secret_key_is_not_configured(): void
    {
        config()->set('services.paymongo.secret_key', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMONGO_SECRET_KEY is not configured');

        app(PayMongoService::class)->createPaymentIntent($this->makeUnpaidInvoice());
    }

    public function test_get_payment_intent_returns_client_key(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_existing' => Http::response([
                'data' => [
                    'id' => 'pi_test_existing',
                    'attributes' => ['client_key' => 'pi_test_existing_client_key'],
                ],
            ]),
        ]);

        $result = app(PayMongoService::class)->getPaymentIntent('pi_test_existing');

        $this->assertSame('pi_test_existing', $result['intent_id']);
        $this->assertSame('pi_test_existing_client_key', $result['client_key']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === PayMongoService::API_BASE.'/payment_intents/pi_test_existing');
    }

    public function test_get_payment_intent_throws_when_api_returns_an_error(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_missing' => Http::response(['errors' => []], 404),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayMongo retrieve payment intent failed');

        app(PayMongoService::class)->getPaymentIntent('pi_test_missing');
    }

    public function test_get_payment_intent_throws_when_intent_belongs_to_another_invoice(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $other = $this->makeUnpaidInvoice();

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_mismatch' => Http::response([
                'data' => [
                    'id' => 'pi_test_mismatch',
                    'attributes' => [
                        'client_key' => 'pi_test_mismatch_client_key',
                        'metadata' => ['invoice_id' => (string) $other->id],
                    ],
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to invoice');

        app(PayMongoService::class)->getPaymentIntent('pi_test_mismatch', $invoice);
    }

    public function test_get_or_create_payment_intent_creates_when_none_exists(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_5',
                    'attributes' => ['client_key' => 'pi_test_intent_5_client_key'],
                ],
            ]),
        ]);

        $invoice = $this->makeUnpaidInvoice();

        $result = app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_intent_5', $result['intent_id']);
        $this->assertSame('pi_test_intent_5', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_get_or_create_payment_intent_reuses_existing_intent(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $invoice->update(['paymongo_payment_intent_id' => 'pi_test_intent_6']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_intent_6' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_6',
                    'attributes' => [
                        'client_key' => 'pi_test_intent_6_client_key',
                        'metadata' => ['invoice_id' => (string) $invoice->id],
                    ],
                ],
            ]),
        ]);

        $result = app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_intent_6', $result['intent_id']);
        $this->assertSame('pi_test_intent_6', $invoice->fresh()->paymongo_payment_intent_id);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_get_or_create_payment_intent_throws_when_invoice_is_not_payable(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $invoice->update(['status' => 'cancelled']);

        $this->expectException(InvoiceNotPayableException::class);

        app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);
    }

    public function test_get_or_create_blocks_new_payment_when_stored_intent_already_succeeded(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $invoice->update(['paymongo_payment_intent_id' => 'pi_test_succeeded']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_succeeded' => Http::response([
                'data' => [
                    'id' => 'pi_test_succeeded',
                    'attributes' => [
                        'status' => 'succeeded',
                        'client_key' => 'pi_test_succeeded_client_key',
                        'metadata' => ['invoice_id' => (string) $invoice->id],
                    ],
                ],
            ]),
        ]);

        $this->expectException(PaymentAlreadyCompletedException::class);

        app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_succeeded', $invoice->fresh()->paymongo_payment_intent_id);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_get_or_create_clears_stale_stored_intent_and_creates_fresh(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $invoice->update(['paymongo_payment_intent_id' => 'pi_test_stale']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_stale' => Http::response(['errors' => []], 404),
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_fresh',
                    'attributes' => ['client_key' => 'pi_test_fresh_client_key'],
                ],
            ]),
        ]);

        $result = app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_fresh', $result['intent_id']);
        $this->assertSame('pi_test_fresh', $invoice->fresh()->paymongo_payment_intent_id);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === PayMongoService::API_BASE.'/payment_intents');
    }

    public function test_get_or_create_clears_stored_intent_with_ownership_mismatch_and_creates_fresh(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $other = $this->makeUnpaidInvoice();
        $invoice->update(['paymongo_payment_intent_id' => 'pi_test_mismatch_2']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_mismatch_2' => Http::response([
                'data' => [
                    'id' => 'pi_test_mismatch_2',
                    'attributes' => [
                        'status' => 'awaiting_payment_method',
                        'client_key' => 'pi_test_mismatch_2_client_key',
                        'metadata' => ['invoice_id' => (string) $other->id],
                    ],
                ],
            ]),
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_fresh_2',
                    'attributes' => ['client_key' => 'pi_test_fresh_2_client_key'],
                ],
            ]),
        ]);

        $result = app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_fresh_2', $result['intent_id']);
        $this->assertSame('pi_test_fresh_2', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_get_or_create_keeps_stored_intent_and_throws_on_5xx_retrieval(): void
    {
        $invoice = $this->makeUnpaidInvoice();
        $invoice->update(['paymongo_payment_intent_id' => 'pi_test_unreachable']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_unreachable' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RuntimeException::class);

        app(PayMongoService::class)->getOrCreatePaymentIntent($invoice);

        $this->assertSame('pi_test_unreachable', $invoice->fresh()->paymongo_payment_intent_id);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_get_payment_intent_status_returns_status(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_status' => Http::response([
                'data' => [
                    'id' => 'pi_test_status',
                    'attributes' => ['status' => 'processing'],
                ],
            ]),
        ]);

        $this->assertSame('processing', app(PayMongoService::class)->getPaymentIntentStatus('pi_test_status'));
    }

    public function test_get_payment_intent_status_returns_null_when_intent_is_unknown(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_gone' => Http::response(['errors' => []], 404),
        ]);

        $this->assertNull(app(PayMongoService::class)->getPaymentIntentStatus('pi_test_gone'));
    }

    public function test_get_payment_intent_status_throws_on_server_error(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_down' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RuntimeException::class);

        app(PayMongoService::class)->getPaymentIntentStatus('pi_test_down');
    }

    public function test_list_paid_payments_fetches_all_pages(): void
    {
        $pageOne = collect(range(0, 49))->map(fn (int $i) => [
            'id' => 'pay_page1_'.$i,
            'type' => 'payment',
            'attributes' => [
                'amount' => 4000,
                'currency' => 'PHP',
                'status' => 'paid',
                'payment_intent_id' => 'pi_page1_'.$i,
                'livemode' => false,
            ],
        ])->all();

        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::sequence()
                ->push(['data' => $pageOne])
                ->push(['data' => [
                    [
                        'id' => 'pay_page2_0',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_page2_0',
                            'livemode' => false,
                        ],
                    ],
                ]]),
        ]);

        $payments = app(PayMongoService::class)->listPaidPayments(now()->subDays(7)->timestamp, now()->timestamp);

        $this->assertCount(51, $payments);
        $this->assertSame('pay_page1_0', $payments[0]['id']);
        $this->assertSame('pay_page2_0', $payments[50]['id']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/payments')) {
                return false;
            }

            return $request->data()['status'] === 'paid'
                && (int) $request->data()['limit'] === 50
                && isset($request->data()['created_at.gte'], $request->data()['created_at.lt']);
        });

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v1/payments')
            && ($request->data()['after'] ?? null) === 'pay_page1_49');
    }

    public function test_list_paid_payments_throws_on_failed_response(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['errors' => []], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PayMongo list payments failed');

        app(PayMongoService::class)->listPaidPayments(now()->subDays(1)->timestamp, now()->timestamp);
    }

    public function test_list_paid_payments_throws_on_malformed_response(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['errors' => []]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('list payments response malformed');

        app(PayMongoService::class)->listPaidPayments(now()->subDays(1)->timestamp, now()->timestamp);
    }

    public function test_create_payment_intent_rejects_unsupported_payment_methods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported payment method(s): crypto');

        app(PayMongoService::class)->createPaymentIntent($this->makeUnpaidInvoice(), ['crypto']);
    }

    public function test_create_payment_intent_rejects_empty_methods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one payment method is required.');

        app(PayMongoService::class)->createPaymentIntent($this->makeUnpaidInvoice(), []);
    }

    public function test_create_payment_intent_retries_on_5xx_then_succeeds(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::sequence()
                ->push(['errors' => [['detail' => 'temporary failure']]], 500)
                ->push([
                    'data' => [
                        'id' => 'pi_test_intent_7',
                        'attributes' => ['client_key' => 'pi_test_intent_7_client_key'],
                    ],
                ]),
        ]);

        $invoice = $this->makeUnpaidInvoice();

        $result = app(PayMongoService::class)->createPaymentIntent($invoice);

        $this->assertSame('pi_test_intent_7', $result['intent_id']);
    }
}
