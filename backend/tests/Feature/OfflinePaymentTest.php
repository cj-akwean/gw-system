<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceNotPayableException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OfflinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentService::class);
    }

    private function payableInvoice(float $total = 456.56, string $status = 'unpaid'): Invoice
    {
        return Invoice::factory()->create([
            'status' => $status,
            'total_amount' => $total,
        ]);
    }

    public function test_unpaid_invoice_is_marked_paid_and_cash_payment_recorded(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();

        $payment = $this->service->recordOfflinePayment(
            invoiceId: $invoice->id,
            amount: 457,
            reference: 'OR-2026-001',
            paidAt: '2026-08-05',
            recordedBy: $admin->id,
        );

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(457.0, (float) $payment->amount);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('OR-2026-001', $payment->reference);
        $this->assertNull($payment->paymongo_reference);
        $this->assertSame($admin->id, $payment->recorded_by);
        $this->assertSame('2026-08-05', $payment->paid_at->toDateString());
    }

    public function test_overdue_invoice_is_marked_paid(): void
    {
        $invoice = $this->payableInvoice(status: 'overdue');

        $this->service->recordOfflinePayment($invoice->id, 457);

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_already_paid_invoice_throws_and_changes_nothing(): void
    {
        $invoice = $this->payableInvoice(status: 'paid');

        try {
            $this->service->recordOfflinePayment($invoice->id, 457);
            $this->fail('Expected InvoiceNotPayableException');
        } catch (InvoiceNotPayableException $e) {
            $this->assertStringContainsString('not payable', $e->getMessage());
        }

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_out_of_tolerance_amount_throws_and_changes_nothing(): void
    {
        $invoice = $this->payableInvoice(total: 500.00);

        try {
            $this->service->recordOfflinePayment($invoice->id, 498.99);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('within ₱1.00', $e->getMessage());
        }

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_amounts_within_one_peso_of_total_are_accepted(): void
    {
        foreach ([500.00, 500.50, 499.01] as $amount) {
            $invoice = $this->payableInvoice(total: 500.00);
            $payment = $this->service->recordOfflinePayment($invoice->id, $amount);
            $this->assertSame(round($amount, 2), (float) $payment->amount);
            $this->assertSame('paid', $invoice->fresh()->status);
        }
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $invoice = $this->payableInvoice();

        $this->expectException(InvalidArgumentException::class);
        $this->service->recordOfflinePayment($invoice->id, 0);
    }

    public function test_disallowed_method_is_rejected(): void
    {
        $invoice = $this->payableInvoice();

        $this->expectException(InvalidArgumentException::class);
        $this->service->recordOfflinePayment($invoice->id, 456, method: 'check');
    }

    public function test_missing_invoice_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->recordOfflinePayment(999999, 456);
    }

    public function test_reference_and_recorded_by_are_optional(): void
    {
        $invoice = $this->payableInvoice();

        $payment = $this->service->recordOfflinePayment($invoice->id, 456);

        $this->assertNull($payment->reference);
        $this->assertNull($payment->recorded_by);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_reference_null_input_is_stored_as_null_not_empty_string(): void
    {
        $invoice = $this->payableInvoice();

        $payment = $this->service->recordOfflinePayment($invoice->id, 456, reference: '');

        $this->assertNull($payment->reference);
    }

    public function test_cash_payment_has_no_unique_paymongo_conflict_when_reference_repeats(): void
    {
        $invoiceA = $this->payableInvoice(total: 100.00);
        $invoiceB = $this->payableInvoice(total: 200.00);

        $this->service->recordOfflinePayment($invoiceA->id, 100, reference: 'OR-001');
        $this->service->recordOfflinePayment($invoiceB->id, 200, reference: 'OR-001');

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(100.0, (float) Payment::where('invoice_id', $invoiceA->id)->sole()->amount);
    }

    public function test_future_payment_date_is_rejected(): void
    {
        $invoice = $this->payableInvoice();

        try {
            $this->service->recordOfflinePayment($invoice->id, 456, paidAt: now()->addDays(2)->format('Y-m-d'));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot be in the future', $e->getMessage());
        }

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_webhook_flow_is_untouched_by_offline_changes(): void
    {
        $invoice = $this->payableInvoice(total: 40.00);

        $payment = $this->service->markPaidFromWebhook($invoice, 'pay_res_offline', 4000, 1619426488);

        $this->assertNotNull($payment);
        $this->assertSame('paymongo', $payment->method);
        $this->assertSame('pay_res_offline', $payment->paymongo_reference);
        $this->assertNull($payment->reference);
    }
}
