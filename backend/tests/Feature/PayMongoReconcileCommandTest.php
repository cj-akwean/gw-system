<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayMongoReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paymongo.secret_key', 'sk_test_dummy_secret');
    }

    private function invoiceWithIntent(string $intentId, string $status = 'unpaid'): Invoice
    {
        return Invoice::factory()->create([
            'status' => $status,
            'total_amount' => 40.00,
            'paymongo_payment_intent_id' => $intentId,
        ]);
    }

    /**
     * Runs the command without the console-output mock so assertions can
     * inspect the full output (multiple substrings on one line are otherwise
     * never all matched — Mockery consumes a write call with the first
     * matching expectation only).
     */
    private function runReconcile(): array
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('paymongo:reconcile');

        return [$exitCode, Artisan::output()];
    }

    public function test_clean_run_reports_no_discrepancies(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['data' => []]),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('no discrepancies found', $output);
    }

    public function test_succeeded_intent_on_unpaid_invoice_is_reported(): void
    {
        $invoice = $this->invoiceWithIntent('pi_test_succeeded_cmd');

        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['data' => []]),
            'api.paymongo.com/v1/payment_intents/pi_test_succeeded_cmd' => Http::response([
                'data' => ['id' => 'pi_test_succeeded_cmd', 'attributes' => ['status' => 'succeeded']],
            ]),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('CHARGED BUT NOT CREDITED', $output);
        $this->assertStringContainsString($invoice->invoice_number, $output);
    }

    public function test_paid_invoice_with_succeeded_intent_is_clean(): void
    {
        $this->invoiceWithIntent('pi_test_paid_cmd', 'paid');

        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['data' => []]),
            'api.paymongo.com/v1/payment_intents/*' => Http::response([
                'data' => ['id' => 'pi_test_paid_cmd', 'attributes' => ['status' => 'succeeded']],
            ]),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('no discrepancies found', $output);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/payment_intents/'));
    }

    public function test_paid_payment_without_local_record_is_reported(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response([
                'data' => [
                    [
                        'id' => 'pay_orphan_1',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_orphan_1',
                            'livemode' => false,
                        ],
                    ],
                ],
            ]),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('PAYMENT WITHOUT LOCAL RECORD', $output);
        $this->assertStringContainsString('pay_orphan_1', $output);
    }

    public function test_payments_with_local_rows_are_not_reported(): void
    {
        $invoice = $this->invoiceWithIntent('pi_test_local_pay');
        $invoice->update(['status' => 'paid']);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_known_1',
            'paid_at' => now(),
        ]);

        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response([
                'data' => [
                    [
                        'id' => 'pay_known_1',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_test_local_pay',
                            'livemode' => false,
                        ],
                    ],
                ],
            ]),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('no discrepancies found', $output);
    }

    public function test_intent_retrieval_failure_is_unchecked_not_a_finding(): void
    {
        $this->invoiceWithIntent('pi_test_down_cmd');

        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['data' => []]),
            'api.paymongo.com/v1/payment_intents/*' => Http::response(['errors' => []], 500),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('UNCHECKED', $output);
        $this->assertStringNotContainsString('CHARGED BUT NOT CREDITED', $output);
    }

    public function test_payment_list_failure_is_unchecked_not_a_finding(): void
    {
        Http::fake([
            'api.paymongo.com/v1/payments*' => Http::response(['errors' => []], 500),
        ]);

        [$exitCode, $output] = $this->runReconcile();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('UNCHECKED', $output);
    }
}
