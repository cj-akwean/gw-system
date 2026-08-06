<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RecordOfflinePaymentCommandTest extends TestCase
{
    use RefreshDatabase;

    private function unpaidInvoice(float $total = 456.56, ?string $invoiceNumber = null): Invoice
    {
        return Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => $total,
            ...($invoiceNumber ? ['invoice_number' => $invoiceNumber] : []),
        ]);
    }

    public function test_command_records_an_offline_cash_payment_by_invoice_id(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->unpaidInvoice();

        $exit = Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            '--recorded-by' => $admin->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('paid', $invoice->fresh()->status);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(round($invoice->total_amount), (float) $payment->amount);
        $this->assertSame('cash', $payment->method);
        $this->assertSame($admin->id, $payment->recorded_by);
        $this->assertStringContainsString('Recorded cash payment', Artisan::output());
    }

    public function test_command_accepts_an_invoice_number_and_explicit_reference(): void
    {
        $invoice = $this->unpaidInvoice(invoiceNumber: 'GW-2026-99999');

        $exit = Artisan::call('payments:record', [
            'invoice' => 'GW-2026-99999',
            'amount' => 457,
            '--reference' => 'OR-TEST-1',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(457.0, (float) Payment::where('invoice_id', $invoice->id)->sole()->amount);
        $this->assertSame('OR-TEST-1', Payment::where('invoice_id', $invoice->id)->sole()->reference);
        $this->assertStringContainsString('OR-TEST-1', Artisan::output());
    }

    public function test_command_rejects_an_unknown_invoice(): void
    {
        $exit = Artisan::call('payments:record', ['invoice' => 999_999]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invoice not found', Artisan::output());
    }

    public function test_command_rejects_an_already_paid_invoice(): void
    {
        $invoice = $this->unpaidInvoice();
        $invoice->update(['status' => 'paid']);

        $exit = Artisan::call('payments:record', ['invoice' => $invoice->id]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_rejects_an_out_of_tolerance_amount(): void
    {
        $invoice = $this->unpaidInvoice(total: 500.00);

        $exit = Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            'amount' => 498.99,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_rejects_a_future_payment_date(): void
    {
        $invoice = $this->unpaidInvoice();

        $exit = Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            '--paid-at' => now()->addDays(2)->format('Y-m-d'),
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_rejects_a_disallowed_method(): void
    {
        $invoice = $this->unpaidInvoice();

        $exit = Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            '--method' => 'check',
        ]);

        $this->assertSame(1, $exit);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_rejects_an_unknown_recorded_by_user(): void
    {
        $invoice = $this->unpaidInvoice();

        $exit = Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            '--recorded-by' => 999_999,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Recorded-by user not found', Artisan::output());
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_command_warns_when_amount_differs_from_total(): void
    {
        $invoice = $this->unpaidInvoice(total: 456.56);

        Artisan::call('payments:record', [
            'invoice' => $invoice->id,
            'amount' => 457,
        ]);

        $this->assertStringContainsString('differs from the invoice total', Artisan::output());
    }
}
