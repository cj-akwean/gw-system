<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicesExportTest extends TestCase
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

    private function invoice(ServiceConnection $connection, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'service_connection_id' => $connection->id,
            'meter_reading_id' => MeterReading::factory(['service_connection_id' => $connection->id]),
            'rate_schedule_id' => RateSchedule::factory(),
        ], $overrides));
    }

    private function exportCsv(array $filters = []): array
    {
        $component = Livewire::actingAs($this->admin(), 'admin')->test(ListInvoices::class);

        foreach ($filters as $name => $data) {
            $component->filterTable($name, $data);
        }

        $component->callAction('exportCsv')->assertFileDownloaded();

        $download = $component->effects['download'] ?? null;

        $this->assertNotNull($download, 'no download effect was emitted');

        $this->assertMatchesRegularExpression('/^invoices-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $download['name']);

        $content = base64_decode($download['content']);

        $this->assertNotFalse($content, 'download content is not valid base64');

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($line = fgetcsv($stream)) !== false) {
            $rows[] = $line;
        }

        return $rows;
    }

    private function assertHeader(array $rows): void
    {
        $this->assertSame([
            'invoice_number',
            'account_number',
            'meter_number',
            'customer_name',
            'status',
            'billing_period_start',
            'billing_period_end',
            'due_date',
            'previous_balance',
            'base_amount',
            'penalty_amount',
            'total_amount',
            'rate_schedule',
            'meter_reading_cu_m_used',
            'meter_reading_entered_at',
        ], $rows[0]);
    }

    public function test_export_downloads_csv_with_headers_and_all_invoices_sorted_by_billing_period_end(): void
    {
        $older = $this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz');
        $newer = $this->connection('GW-00002', 'MTR-00002', 'Ben Santos');
        $schedule = RateSchedule::factory()->create(['name' => 'Standard Flat Rate']);

        $olderReading = MeterReading::factory()->create([
            'service_connection_id' => $older->id,
            'cu_m_used' => 45.00,
            'entered_at' => '2026-07-15 10:45:00',
        ]);

        $newerReading = MeterReading::factory()->create([
            'service_connection_id' => $newer->id,
            'cu_m_used' => 87.25,
            'entered_at' => '2026-08-15 08:20:00',
        ]);

        $olderInvoice = $this->invoice($older, [
            'status' => 'paid',
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'due_date' => '2026-07-15',
            'previous_balance' => 0,
            'base_amount' => 1245.50,
            'penalty_amount' => 0,
            'total_amount' => 1245.50,
            'rate_schedule_id' => $schedule->id,
            'meter_reading_id' => $olderReading->id,
        ]);

        $newerInvoice = $this->invoice($newer, [
            'status' => 'unpaid',
            'billing_period_start' => '2026-07-01',
            'billing_period_end' => '2026-07-31',
            'due_date' => '2026-08-15',
            'previous_balance' => 100.00,
            'base_amount' => 750.50,
            'penalty_amount' => 25.00,
            'total_amount' => 875.50,
            'rate_schedule_id' => $schedule->id,
            'meter_reading_id' => $newerReading->id,
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame([
            $newerInvoice->invoice_number,
            'GW-00002',
            'MTR-00002',
            'Ben Santos',
            'Unpaid',
            '2026-07-01',
            '2026-07-31',
            '2026-08-15',
            '100',
            '750.5',
            '25',
            '875.5',
            'Standard Flat Rate',
            '87.25',
            '2026-08-15 08:20:00',
        ], $rows[1]);

        $this->assertSame([
            $olderInvoice->invoice_number,
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            'Paid',
            '2026-06-01',
            '2026-06-30',
            '2026-07-15',
            '0',
            '1245.5',
            '0',
            '1245.5',
            'Standard Flat Rate',
            '45',
            '2026-07-15 10:45:00',
        ], $rows[2]);

        $this->assertCount(3, $rows);
    }

    public function test_export_respects_status_filter(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), [
            'status' => 'paid',
            'billing_period_end' => '2026-07-31',
        ]);
        $this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), [
            'status' => 'unpaid',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv(['status' => 'paid']);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('Paid', $rows[1][4]);
        $this->assertSame('GW-00001', $rows[1][1]);
    }

    public function test_export_respects_multiple_status_filter_values(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), [
            'status' => 'paid',
            'billing_period_end' => '2026-07-31',
        ]);
        $this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), [
            'status' => 'overdue',
            'billing_period_end' => '2026-07-31',
        ]);
        $this->invoice($this->connection('GW-00003', 'MTR-00003', 'Carla Reyes'), [
            'status' => 'unpaid',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv(['status' => ['paid', 'overdue']]);

        $this->assertHeader($rows);

        $this->assertCount(3, $rows);
    }

    public function test_export_respects_due_date_range_filter(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), [
            'status' => 'unpaid',
            'due_date' => '2026-01-15',
            'billing_period_end' => '2026-06-30',
        ]);
        $this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), [
            'status' => 'unpaid',
            'due_date' => '2026-08-15',
            'billing_period_end' => '2026-07-31',
        ]);
        $this->invoice($this->connection('GW-00003', 'MTR-00003', 'Carla Reyes'), [
            'status' => 'unpaid',
            'due_date' => '2026-09-15',
            'billing_period_end' => '2026-08-31',
        ]);

        $rows = $this->exportCsv(['due_date' => [
            'due_from' => '2026-08-01',
            'due_until' => '2026-08-31',
        ]]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('GW-00002', $rows[1][1]);
    }

    public function test_export_respects_combined_filters(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), [
            'status' => 'unpaid',
            'due_date' => '2026-08-15',
            'billing_period_end' => '2026-07-31',
        ]);
        $this->invoice($this->connection('GW-00002', 'MTR-00002', 'Ben Santos'), [
            'status' => 'paid',
            'due_date' => '2026-08-15',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv([
            'status' => 'unpaid',
            'due_date' => ['due_from' => '2026-08-01', 'due_until' => '2026-08-31'],
        ]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('GW-00001', $rows[1][1]);
    }

    public function test_export_with_no_matching_rows_downloads_headers_only(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', 'Ana Dela Cruz'), [
            'status' => 'unpaid',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv(['status' => 'paid']);

        $this->assertHeader($rows);

        $this->assertCount(1, $rows);
    }

    public function test_export_escapes_csv_formula_injection_in_free_text_cells(): void
    {
        $this->invoice($this->connection('=cmd()', 'MTR-00001', '@evil.example'), [
            'status' => 'unpaid',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame("'=cmd()", $rows[1][1]);
        $this->assertSame("'@evil.example", $rows[1][3]);
    }

    public function test_export_escapes_newline_prefixed_formula_injection(): void
    {
        $this->invoice($this->connection('GW-00001', 'MTR-00001', "\n=cmd()"), [
            'status' => 'unpaid',
            'billing_period_end' => '2026-07-31',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame("'\n=cmd()", $rows[1][3]);
        $this->assertCount(2, $rows);
    }
}