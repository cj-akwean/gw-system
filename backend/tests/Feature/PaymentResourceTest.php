<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    private function payableInvoice(float $total = 456.56): Invoice
    {
        return Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => $total,
        ]);
    }

    public function test_create_page_records_cash_payment_and_marks_invoice_paid(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();

        Livewire::actingAs($admin, 'admin')
            ->test(CreatePayment::class)
            ->fillForm([
                'invoice_id' => $invoice->id,
                'amount' => '457',
                'method' => 'cash',
                'reference' => 'OR-2026-100',
                'paid_at' => '2026-08-06',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 457.00,
            'method' => 'cash',
            'reference' => 'OR-2026-100',
            'paymongo_reference' => null,
            'recorded_by' => $admin->id,
            'paid_at' => '2026-08-06 00:00:00',
        ]);
    }

    public function test_out_of_tolerance_amount_is_rejected_without_changing_the_invoice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice(total: 500.00);

        Livewire::actingAs($admin, 'admin')
            ->test(CreatePayment::class)
            ->fillForm([
                'invoice_id' => $invoice->id,
                'amount' => '498.99',
                'method' => 'cash',
                'reference' => 'OR-2026-101',
                'paid_at' => '2026-08-06',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_already_paid_invoice_is_never_double_recorded(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        Livewire::actingAs($admin, 'admin')
            ->test(CreatePayment::class)
            ->fillForm([
                'invoice_id' => $invoice->id,
                'amount' => '457',
                'method' => 'cash',
                'reference' => 'OR-2026-102',
                'paid_at' => '2026-08-06',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_view_page_renders_paymongo_method_with_channel_and_reference_fallback(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_view_test_1',
            'paymongo_source' => 'gcash',
            'reference' => null,
            'recorded_by' => null,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertFormSet([
                'method' => 'paymongo',
                'reference' => 'pay_view_test_1',
            ])
            ->assertSee('PayMongo · GCash')
            ->assertSee('PayMongo');
    }

    public function test_view_page_renders_paymongo_without_channel_when_source_is_null(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_view_test_2',
            'paymongo_source' => null,
            'reference' => null,
            'recorded_by' => null,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertFormSet([
                'method' => 'paymongo',
                'reference' => 'pay_view_test_2',
            ])
            ->assertSee('PayMongo')
            ->assertDontSee('PayMongo ·');
    }

    public function test_view_page_renders_cash_method_and_or_reference(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 457.00,
            'method' => 'cash',
            'paymongo_reference' => null,
            'paymongo_source' => null,
            'reference' => 'OR-2026-200',
            'recorded_by' => $admin->id,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertFormSet([
                'method' => 'cash',
                'reference' => 'OR-2026-200',
            ])
            ->assertSee('Cash')
            ->assertSee($admin->name);
    }
}
