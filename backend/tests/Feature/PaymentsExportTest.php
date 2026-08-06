<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentsExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function connection(string $account, string $meter, string $name): ServiceConnection
    {
        return ServiceConnection::factory()->create([
            'account_number' => $account,
            'meter_number' => $meter,
            'registered_name' => $name,
        ]);
    }

    private function invoice(ServiceConnection $connection, string $status): Invoice
    {
        return Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'status' => $status,
        ]);
    }

    private function payment(Invoice $invoice, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge(['invoice_id' => $invoice->id], $overrides));
    }

    private function exportCsv(array $filters = []): array
    {
        $component = Livewire::actingAs($this->admin(), 'admin')->test(ListPayments::class);

        foreach ($filters as $name => $data) {
            $component->filterTable($name, $data);
        }

        $component->callAction('exportCsv')->assertFileDownloaded();

        $download = $component->effects['download'] ?? null;

        $this->assertNotNull($download, 'no download effect was emitted');

        $this->assertMatchesRegularExpression('/^payments-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $download['name']);

        $content = base64_decode($download['content']);

        $this->assertNotFalse($content, 'download content is not valid base64');

        $lines = preg_split('/\r\n|\r|\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $line): array => str_getcsv($line), $lines);
    }

    private function assertHeader(array $rows): void
    {
        $this->assertSame([
            'paid_at',
            'invoice_no',
            'account_no',
            'meter_no',
            'customer_name',
            'amount',
            'method',
            'reference',
            'payer_name',
            'payer_email',
            'recorded_by',
        ], $rows[0]);
    }

    public function test_export_downloads_csv_with_headers_and_all_payments_sorted_by_paid_at(): void
    {
        $older = $this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz');
        $newer = $this->connection('GW-00002', 'MTR-00002', 'Ben Santos');

        $olderInvoice = $this->invoice($older, 'paid');
        $newerInvoice = $this->invoice($newer, 'paid');

        $this->payment($olderInvoice, [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);
        $this->payment($newerInvoice, [
            'amount' => 750.50,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_1',
            'paymongo_source' => 'gcash',
            'payer_name' => 'Ben Santos',
            'payer_email' => 'ben@example.com',
            'paid_at' => '2026-08-01 10:30:00',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame([
            '2026-08-01 10:30:00',
            $newerInvoice->invoice_number,
            'GW-00002',
            'MTR-00002',
            'Ben Santos',
            '750.5',
            'PayMongo · GCash',
            'pay_test_1',
            'Ben Santos',
            'ben@example.com',
            'PayMongo',
        ], $rows[1]);

        $this->assertSame([
            '2026-07-01 09:00:00',
            $olderInvoice->invoice_number,
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            '500',
            'Cash',
            'OR-100',
            '',
            '',
            '—',
        ], $rows[2]);

        $this->assertCount(3, $rows);
    }

    public function test_export_respects_method_filter(): void
    {
        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);
        $this->payment($this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), 'paid'), [
            'amount' => 750.50,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_1',
            'paid_at' => '2026-08-01 10:30:00',
        ]);

        $rows = $this->exportCsv(['method' => 'cash']);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('Cash', $rows[1][6]);
        $this->assertSame('GW-00001', $rows[1][2]);
    }

    public function test_export_respects_invoice_status_filter(): void
    {
        $overdue = $this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz');
        $paid = $this->connection('GW-00002', 'MTR-00002', 'Ben Santos');

        $this->payment($this->invoice($overdue, 'overdue'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);
        $this->payment($this->invoice($paid, 'paid'), [
            'amount' => 750.50,
            'method' => 'cash',
            'reference' => 'OR-200',
            'paid_at' => '2026-08-01 10:30:00',
        ]);

        $rows = $this->exportCsv(['invoice.status' => 'overdue']);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('OR-100', $rows[1][7]);
    }

    public function test_export_respects_invoice_paid_status_filter(): void
    {
        $overdue = $this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz');
        $paid = $this->connection('GW-00002', 'MTR-00002', 'Ben Santos');

        $this->payment($this->invoice($overdue, 'overdue'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);
        $this->payment($this->invoice($paid, 'paid'), [
            'amount' => 750.50,
            'method' => 'cash',
            'reference' => 'OR-200',
            'paid_at' => '2026-08-01 10:30:00',
        ]);

        $rows = $this->exportCsv(['invoice.status' => 'paid']);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('OR-200', $rows[1][7]);
    }

    public function test_export_respects_paid_at_range_filter(): void
    {
        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);
        $this->payment($this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), 'paid'), [
            'amount' => 750.50,
            'method' => 'cash',
            'reference' => 'OR-200',
            'paid_at' => '2026-08-15 10:30:00',
        ]);
        $this->payment($this->invoice($this->connection('GW-00003', 'MTR-00003', 'Carla Reyes'), 'paid'), [
            'amount' => 900.00,
            'method' => 'cash',
            'reference' => 'OR-300',
            'paid_at' => '2026-09-01 11:00:00',
        ]);

        $rows = $this->exportCsv(['paid_at' => [
            'paid_from' => '2026-08-01',
            'paid_until' => '2026-08-31',
        ]]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('OR-200', $rows[1][7]);
    }

    public function test_export_respects_combined_filters(): void
    {
        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_1',
            'paid_at' => '2026-08-10 09:00:00',
        ]);
        $this->payment($this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), 'paid'), [
            'amount' => 750.50,
            'method' => 'cash',
            'reference' => 'OR-200',
            'paid_at' => '2026-08-15 10:30:00',
        ]);

        $rows = $this->exportCsv([
            'method' => 'cash',
            'paid_at' => ['paid_from' => '2026-08-01', 'paid_until' => '2026-08-31'],
        ]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('OR-200', $rows[1][7]);
    }

    public function test_export_with_no_matching_rows_downloads_headers_only(): void
    {
        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'paid_at' => '2026-07-01 09:00:00',
        ]);

        $rows = $this->exportCsv(['method' => 'bank_transfer']);

        $this->assertHeader($rows);

        $this->assertCount(1, $rows);
    }

    public function test_export_escapes_csv_formula_injection_in_free_text_cells(): void
    {
        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_test_1',
            'payer_name' => '=HYPERLINK("http://evil.example")',
            'payer_email' => '@evil.example',
            'paid_at' => '2026-08-01 09:00:00',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $rows[1][8]);
        $this->assertSame("'@evil.example", $rows[1][9]);
    }

    public function test_export_records_who_recorded_offline_payments(): void
    {
        $clerk = User::factory()->create(['is_admin' => true, 'name' => 'Office Clerk']);

        $this->payment($this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), 'paid'), [
            'amount' => 500.00,
            'method' => 'cash',
            'reference' => 'OR-100',
            'recorded_by' => $clerk->id,
            'paid_at' => '2026-08-01 09:00:00',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame('Office Clerk', $rows[1][10]);
    }
}
