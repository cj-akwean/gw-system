<?php

namespace Tests\Unit\Exports;

use App\Exports\ServiceConnectionsExport;
use App\Models\Barangay;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceConnectionsExportTest extends TestCase
{
    private function exportFor(ServiceConnection $connection): ServiceConnectionsExport
    {
        return new ServiceConnectionsExport(ServiceConnection::query());
    }

    public function test_headings_match_the_specification(): void
    {
        $export = $this->exportFor(new ServiceConnection);

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
        ], $export->headings());
    }

    public function test_map_outputs_a_fully_populated_row(): void
    {
        $barangay = (new Barangay)->forceFill(['name' => 'Mauraro']);
        $rateSchedule = (new RateSchedule)->forceFill(['name' => 'Standard Flat Rate']);

        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => 'GW-00001',
                'meter_number' => 'MTR-00001',
                'registered_name' => 'Ana Dela Cruz',
                'address' => '123 Poblacion 3',
                'status' => 'active',
                'pending_balance' => 1250.50,
                'created_at' => now(),
            ])
            ->setRelation('barangay', $barangay)
            ->setRelation('rateSchedule', $rateSchedule);

        $connection->connection_date = Carbon::parse('2024-03-15');
        $connection->created_at = Carbon::parse('2026-07-01 09:00:00');

        $row = $this->exportFor($connection)->map($connection);

        $this->assertSame([
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            'Mauraro',
            '123 Poblacion 3',
            'active',
            '2024-03-15',
            'Standard Flat Rate',
            '1250.50',
            '2026-07-01 09:00:00',
        ], $row);
    }

    public function test_map_handles_null_rate_schedule_and_null_balance(): void
    {
        $barangay = (new Barangay)->forceFill(['name' => 'San Rafael']);

        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => 'GW-00002',
                'meter_number' => 'MTR-00002',
                'registered_name' => 'Ben Santos',
                'address' => '456 San Rafael',
                'status' => 'disconnected',
            ])
            ->setRelation('barangay', $barangay)
            ->setRelation('rateSchedule', null);

        $connection->connection_date = Carbon::parse('2024-03-15');

        $row = $this->exportFor($connection)->map($connection);

        $this->assertSame('', $row[7]);
        $this->assertSame('0.00', $row[8]);
        $this->assertNull($row[9]);
    }

    public function test_map_outputs_an_empty_date_for_a_null_connection_date(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => 'GW-00003',
                'meter_number' => 'MTR-00003',
                'registered_name' => 'Carla Reyes',
                'address' => '123 San Francisco',
                'status' => 'active',
            ])
            ->setRelation('barangay', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($connection)->map($connection);

        $this->assertNull($row[6]);
        $this->assertSame('', $row[3]);
    }

    public function test_map_escapes_formula_injection_in_free_text_fields(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => '=cmd()',
                'meter_number' => '@evil.com',
                'registered_name' => '+SUM(A1)',
                'address' => '-1+1',
                'status' => "\t=cmd()",
            ])
            ->setRelation('barangay', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($connection)->map($connection);

        $this->assertSame("'=cmd()", $row[0]);
        $this->assertSame("'@evil.com", $row[1]);
        $this->assertSame("'+SUM(A1)", $row[2]);
        $this->assertSame("'-1+1", $row[4]);
        $this->assertSame("'\t=cmd()", $row[5]);
    }

    public function test_map_escapes_formula_injection_in_relation_names(): void
    {
        $barangay = (new Barangay)->forceFill(['name' => '=HYPERLINK("http://evil.example")']);
        $rateSchedule = (new RateSchedule)->forceFill(['name' => '@rate']);

        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => 'GW-00004',
                'meter_number' => 'MTR-00004',
                'registered_name' => 'Diana',
                'address' => '123',
                'status' => 'active',
            ])
            ->setRelation('barangay', $barangay)
            ->setRelation('rateSchedule', $rateSchedule);

        $row = $this->exportFor($connection)->map($connection);

        $this->assertSame("'=HYPERLINK(\"http://evil.example\")", $row[3]);
        $this->assertSame("'@rate", $row[7]);
    }

    public function test_map_escapes_newline_prefixed_formula_injection(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill([
                'account_number' => 'GW-00005',
                'meter_number' => 'MTR-00005',
                'registered_name' => "\n=cmd()",
                'address' => "\r=cmd()",
                'status' => 'active',
            ])
            ->setRelation('barangay', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($connection)->map($connection);

        $this->assertSame("'\n=cmd()", $row[2]);
        $this->assertSame("'\r=cmd()", $row[4]);
    }
}
