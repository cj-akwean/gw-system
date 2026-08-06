<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function invoice(string $status, float $total): Invoice
    {
        return Invoice::factory()->create([
            'status' => $status,
            'total_amount' => $total,
            'previous_balance' => 0,
            'base_amount' => $total,
            'penalty_amount' => 0,
        ]);
    }

    public function test_list_renders_invoices_with_status_badges(): void
    {
        $unpaid = $this->invoice('unpaid', 500.00);
        $overdue = $this->invoice('overdue', 750.00);
        $paid = $this->invoice('paid', 300.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$unpaid, $overdue, $paid])
            ->assertSee('500.00')
            ->assertSee('750.00')
            ->assertSee('300.00');
    }

    public function test_list_status_filter_unpaid(): void
    {
        $unpaid = $this->invoice('unpaid', 500.00);
        $overdue = $this->invoice('overdue', 750.00);

        Livewire::withQueryParams([
            'filters' => json_encode(['status' => ['values' => ['unpaid']]]),
        ])->actingAs($this->admin(), 'admin')
            ->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$unpaid])
            ->assertCanNotSeeTableRecords([$overdue]);
    }

    public function test_list_status_filter_overdue(): void
    {
        $unpaid = $this->invoice('unpaid', 500.00);
        $overdue = $this->invoice('overdue', 750.00);

        Livewire::withQueryParams([
            'filters' => json_encode(['status' => ['values' => ['overdue']]]),
        ])->actingAs($this->admin(), 'admin')
            ->test(ListInvoices::class)
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$unpaid]);
    }

    public function test_view_page_renders_full_billing_breakdown(): void
    {
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'ACCT-123',
            'meter_number' => 'MTR-456',
            'registered_name' => 'Juan dela Cruz',
        ]);
        $invoice = Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => 1234.56,
            'previous_balance' => 200.00,
            'base_amount' => 1000.00,
            'penalty_amount' => 34.56,
            'service_connection_id' => $connection->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'due_date' => '2026-07-15',
        ]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertSee('ACCT-123')
            ->assertSee('MTR-456')
            ->assertSee('Juan dela Cruz')
            ->assertSee('₱1,000.00')
            ->assertSee('₱200.00')
            ->assertSee('₱34.56')
            ->assertSee('₱1,234.56')
            ->assertSee('Unpaid');
    }

    public function test_mark_paid_action_visible_for_unpaid(): void
    {
        $invoice = $this->invoice('unpaid', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('markPaid');
    }

    public function test_mark_paid_action_visible_for_overdue(): void
    {
        $invoice = $this->invoice('overdue', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionVisible('markPaid');
    }

    public function test_mark_paid_action_hidden_for_paid(): void
    {
        $invoice = $this->invoice('paid', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('markPaid');
    }

    public function test_mark_paid_records_offline_payment_and_flips_status(): void
    {
        $admin = $this->admin();
        $invoice = $this->invoice('unpaid', 456.56);

        Livewire::actingAs($admin, 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('markPaid', data: [
                'amount' => '457',
                'method' => 'cash',
                'reference' => 'OR-2026-500',
                'paid_at' => '2026-08-06',
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 457.00,
            'method' => 'cash',
            'reference' => 'OR-2026-500',
            'paymongo_reference' => null,
            'recorded_by' => $admin->id,
            'paid_at' => '2026-08-06 00:00:00',
        ]);
    }

    public function test_mark_paid_rejects_out_of_tolerance_amount(): void
    {
        $invoice = $this->invoice('unpaid', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('markPaid', data: [
                'amount' => '498.99',
                'method' => 'cash',
                'reference' => 'OR-2026-501',
                'paid_at' => '2026-08-06',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mark_paid_rejects_future_date(): void
    {
        $invoice = $this->invoice('unpaid', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->callAction('markPaid', data: [
                'amount' => '500',
                'method' => 'cash',
                'reference' => 'OR-2026-502',
                'paid_at' => now()->addDays(5)->toDateString(),
            ])
            ->assertHasFormErrors(['paid_at']);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mark_paid_already_paid_never_double_recorded(): void
    {
        $invoice = $this->invoice('paid', 500.00);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewInvoice::class, ['record' => $invoice->id])
            ->assertActionHidden('markPaid');

        $this->assertDatabaseCount('payments', 0);
    }
}
