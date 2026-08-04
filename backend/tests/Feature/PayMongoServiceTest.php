<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceNotPayableException;
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
