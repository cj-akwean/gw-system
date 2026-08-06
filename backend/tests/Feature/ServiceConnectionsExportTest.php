<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceConnectionResource\Pages\ListServiceConnections;
use App\Models\Barangay;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ServiceConnectionsExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function barangay(string $name): Barangay
    {
        return Barangay::factory()->create(['name' => $name]);
    }

    private function connection(array $overrides = []): ServiceConnection
    {
        return ServiceConnection::factory()->create($overrides);
    }

    private function exportCsv(array $filters = []): array
    {
        $component = Livewire::actingAs($this->admin(), 'admin')->test(ListServiceConnections::class);

        foreach ($filters as $name => $data) {
            $component->filterTable($name, $data);
        }

        $component->callAction('exportCsv')->assertFileDownloaded();

        $download = $component->effects['download'] ?? null;

        $this->assertNotNull($download, 'no download effect was emitted');

        $this->assertMatchesRegularExpression('/^service-connections-\d{4}-\d{2}-\d{2}-\d{6}\.csv$/', $download['name']);

        $content = base64_decode($download['content']);

        $this->assertNotFalse($content, 'download content is not valid base64');

        $lines = preg_split('/\r\n|\r|\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $line): array => str_getcsv($line), $lines);
    }

    private function assertHeader(array $rows): void
    {
        $this->assertSame([
            'account_number',
            'meter_number',
            'name',
            'barangay',
            'address',
            'status',
            'connection_date',
            'rate_schedule',
            'pending_balance',
            'created_at',
        ], $rows[0]);
    }

    public function test_export_downloads_csv_with_headers_and_all_connections_sorted_by_account_number(): void
    {
        $rate = RateSchedule::factory()->create(['name' => 'Standard Flat Rate']);
        $hauraro = $this->barangay('Mauraro');
        $poblacion = $this->barangay('Poblacion');

        $first = $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Ana Dela Cruz',
            'barangay_id' => $hauraro->id,
            'address' => '123 Poblacion St',
            'status' => 'active',
            'connection_date' => '2024-03-15',
            'rate_schedule_id' => $rate->id,
        ]);

        $second = $this->connection([
            'account_number' => 'GW-00002',
            'meter_number' => 'MTR-00002',
            'registered_name' => 'Ben Santos',
            'barangay_id' => $poblacion->id,
            'address' => '456 Poblacion St',
            'status' => 'disconnected',
            'connection_date' => '2025-01-10',
            'rate_schedule_id' => null,
        ]);

        Invoice::factory()->create([
            'service_connection_id' => $first->id,
            'meter_reading_id' => MeterReading::factory(['service_connection_id' => $first->id]),
            'status' => 'unpaid',
            'previous_balance' => 0,
            'base_amount' => 1245.50,
            'penalty_amount' => 0,
            'total_amount' => 1245.50,
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame([
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            'Mauraro',
            '123 Poblacion St',
            'active',
            '2024-03-15',
            'Standard Flat Rate',
            '1245.5',
            $first->created_at->toDateTimeString(),
        ], $rows[1]);

        $this->assertSame([
            'GW-00002',
            'MTR-00002',
            'Ben Santos',
            'Poblacion',
            '456 Poblacion St',
            'disconnected',
            '2025-01-10',
            '',
            '0',
            $second->created_at->toDateTimeString(),
        ], $rows[2]);

        $this->assertCount(3, $rows);
    }

    public function test_export_respects_status_filter(): void
    {
        $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Ana Dela Cruz',
            'barangay_id' => $this->barangay('Mauraro')->id,
            'status' => 'active',
        ]);

        $this->connection([
            'account_number' => 'GW-00002',
            'meter_number' => 'MTR-00002',
            'registered_name' => 'Ben Santos',
            'barangay_id' => $this->barangay('Poblacion')->id,
            'status' => 'disconnected',
        ]);

        $rows = $this->exportCsv(['status' => 'active']);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('GW-00001', $rows[1][0]);
        $this->assertSame('active', $rows[1][5]);
    }

    public function test_export_respects_barangay_filter(): void
    {
        $mauraro = $this->barangay('Mauraro');

        $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Ana Dela Cruz',
            'barangay_id' => $mauraro->id,
            'status' => 'active',
        ]);

        $this->connection([
            'account_number' => 'GW-00002',
            'meter_number' => 'MTR-00002',
            'registered_name' => 'Ben Santos',
            'barangay_id' => $this->barangay('Poblacion')->id,
            'status' => 'active',
        ]);

        $rows = $this->exportCsv(['barangay' => $mauraro->id]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('GW-00001', $rows[1][0]);
        $this->assertSame('Mauraro', $rows[1][3]);
    }

    public function test_export_respects_combined_filters(): void
    {
        $mauraro = $this->barangay('Mauraro');
        $poblacion = $this->barangay('Poblacion');

        $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Ana Dela Cruz',
            'barangay_id' => $mauraro->id,
            'status' => 'active',
        ]);

        $this->connection([
            'account_number' => 'GW-00002',
            'meter_number' => 'MTR-00002',
            'registered_name' => 'Ben Santos',
            'barangay_id' => $mauraro->id,
            'status' => 'inactive',
        ]);

        $this->connection([
            'account_number' => 'GW-00003',
            'meter_number' => 'MTR-00003',
            'registered_name' => 'Carla Reyes',
            'barangay_id' => $poblacion->id,
            'status' => 'active',
        ]);

        $rows = $this->exportCsv([
            'status' => 'active',
            'barangay' => $mauraro->id,
        ]);

        $this->assertHeader($rows);

        $this->assertCount(2, $rows);
        $this->assertSame('GW-00001', $rows[1][0]);
    }

    public function test_export_with_no_matching_rows_downloads_headers_only(): void
    {
        $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Ana Dela Cruz',
            'barangay_id' => $this->barangay('Mauraro')->id,
            'status' => 'active',
        ]);

        $rows = $this->exportCsv(['status' => 'disconnected']);

        $this->assertHeader($rows);

        $this->assertCount(1, $rows);
    }

    public function test_export_escapes_csv_formula_injection_in_free_text_cells(): void
    {
        $this->connection([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => '=HYPERLINK("http://evil.example")',
            'barangay_id' => $this->barangay('Poblacion')->id,
            'address' => '@evil.example',
            'status' => 'active',
        ]);

        $rows = $this->exportCsv();

        $this->assertHeader($rows);

        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $rows[1][2]);
        $this->assertSame("'@evil.example", $rows[1][4]);
    }
}