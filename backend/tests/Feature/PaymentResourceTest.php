<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PaymentResource\Pages\CreatePayment;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
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

    public function test_view_page_renders_the_payer_when_captured(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_view_test_3',
            'payer_name' => 'Zooey Doge',
            'payer_email' => 'zooey@example.com',
            'payer_phone' => '09171234567',
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertSee('Zooey Doge · zooey@example.com · 09171234567');
    }

    public function test_view_page_shows_a_dash_for_missing_payer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'cash',
            'payer_name' => null,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertSee('Payer')
            ->assertSee('—');
    }

    public function test_list_renders_reference_processed_by_and_payer_columns_for_paymongo_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_list_test_1',
            'paymongo_source' => 'gcash',
            'reference' => null,
            'payer_name' => 'Zooey Doge',
            'payer_email' => 'zooey@example.com',
            'recorded_by' => null,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('pay_list_test_1')
            ->assertSee('PayMongo · GCash')
            ->assertSee('Processed By')
            ->assertSee('Zooey Doge');
    }

    public function test_list_renders_reference_and_processed_by_for_cash_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'name' => 'Office Clerk']);
        $invoice = $this->payableInvoice();
        $invoice->update(['status' => 'paid']);

        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 456.00,
            'method' => 'cash',
            'paymongo_reference' => null,
            'reference' => 'OR-2026-300',
            'recorded_by' => $admin->id,
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('OR-2026-300')
            ->assertSee('Office Clerk');
    }

    public function test_record_title_uses_reference_when_present(): void
    {
        $invoice = $this->payableInvoice();
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'reference' => 'OR-2026-777',
        ]);

        $this->assertSame('OR-2026-777', PaymentResource::getRecordTitle($payment));
    }

    public function test_record_title_falls_back_to_payment_number(): void
    {
        $invoice = $this->payableInvoice();
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'reference' => null,
        ]);

        $this->assertSame('Payment #'.$payment->id, PaymentResource::getRecordTitle($payment));
    }

    public function test_view_page_heading_shows_human_title(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $invoice = $this->payableInvoice();
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'reference' => 'OR-2026-888',
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewPayment::class, ['record' => $payment->id])
            ->assertSee('View OR-2026-888');
    }
}
