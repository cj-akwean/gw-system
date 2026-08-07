<?php

namespace Tests\Unit\Exports;

use App\Exports\InvoicesExport;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InvoicesExportTest extends TestCase
{
    use RefreshDatabase;

    private function exportFor(Invoice $invoice): InvoicesExport
    {
        return new InvoicesExport(Invoice::query());
    }

    public function test_headings_match_the_specification(): void
    {
        $export = $this->exportFor(new Invoice);

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
        ], $export->headings());
    }

    public function test_map_outputs_a_fully_populated_row(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill(['account_number' => 'GW-00001', 'meter_number' => 'MTR-00001', 'registered_name' => 'Ana Dela Cruz']);
        $reading = (new MeterReading)
            ->forceFill(['cu_m_used' => 124.50]);
        $schedule = (new RateSchedule)->forceFill(['name' => 'Standard Flat Rate']);

        $reading->entered_at = Carbon::parse('2026-07-01 08:15:00');

        $invoice = (new Invoice)->forceFill([
            'invoice_number' => 'GW-2026-00001',
            'status' => 'paid',
            'previous_balance' => 0,
            'base_amount' => 1245.50,
            'penalty_amount' => 0,
            'total_amount' => 1245.50,
            'due_date' => Carbon::parse('2026-08-01'),
        ])
            ->setRelation('serviceConnection', $connection)
            ->setRelation('meterReading', $reading)
            ->setRelation('rateSchedule', $schedule);

        $invoice->billing_period_start = Carbon::parse('2026-06-01');
        $invoice->billing_period_end = Carbon::parse('2026-07-31');

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame([
            'GW-2026-00001',
            'GW-00001',
            'MTR-00001',
            'Ana Dela Cruz',
            'Paid',
            '2026-06-01',
            '2026-07-31',
            '2026-08-01',
            '0.00',
            '1245.50',
            '0.00',
            '1245.50',
            'Standard Flat Rate',
            '124.50',
            '2026-07-01 08:15:00',
        ], $row);
    }

    public function test_map_handles_an_invoice_without_a_service_connection(): void
    {
        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00002', 'status' => 'unpaid', 'total_amount' => 100.00])
            ->setRelation('serviceConnection', null)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame('GW-2026-00002', $row[0]);
        $this->assertSame('', $row[1]);
        $this->assertSame('', $row[2]);
        $this->assertSame('', $row[3]);
        $this->assertSame('Unpaid', $row[4]);
    }

    public function test_map_handles_missing_meter_reading_and_rate_schedule(): void
    {
        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00003', 'status' => 'overdue'])
            ->setRelation('serviceConnection', null)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame('', $row[12]);
        $this->assertSame('', $row[13]);
        $this->assertNull($row[14]);
    }

    public function test_map_escapes_formula_injection_in_text_fields(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill(['account_number' => '=cmd()', 'meter_number' => '@evil', 'registered_name' => '+1+2']);
        $schedule = (new RateSchedule)->forceFill(['name' => '-cmd()']);

        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00004', 'status' => 'paid'])
            ->setRelation('serviceConnection', $connection)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', $schedule);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame("'=cmd()", $row[1]);
        $this->assertSame("'@evil", $row[2]);
        $this->assertSame("'+1+2", $row[3]);
        $this->assertSame("'-cmd()", $row[12]);
    }

    public function test_map_escapes_tab_newline_and_carriage_return_prefixed_injection(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill(['account_number' => "\t=cmd()", 'meter_number' => "\r=cmd()", 'registered_name' => "\n=cmd()"]);

        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00005', 'status' => 'paid'])
            ->setRelation('serviceConnection', $connection)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame("'\t=cmd()", $row[1]);
        $this->assertSame("'\r=cmd()", $row[2]);
        $this->assertSame("'\n=cmd()", $row[3]);
    }

    public function test_map_outputs_null_dates_for_null_date_attributes(): void
    {
        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00006', 'status' => 'paid'])
            ->setRelation('serviceConnection', null)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertNull($row[5]);
        $this->assertNull($row[6]);
        $this->assertNull($row[7]);
    }

    public function test_map_formats_negative_and_zero_amounts_to_two_decimals(): void
    {
        $invoice = (new Invoice)
            ->forceFill([
                'invoice_number' => 'GW-2026-00007',
                'status' => 'paid',
                'previous_balance' => -25.5,
                'base_amount' => 0,
                'penalty_amount' => 50,
                'total_amount' => 24.5,
            ])
            ->setRelation('serviceConnection', null)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame('-25.50', $row[8]);
        $this->assertSame('0.00', $row[9]);
        $this->assertSame('50.00', $row[10]);
        $this->assertSame('24.50', $row[11]);
    }

    public function test_map_escapes_null_byte_prefixed_injection(): void
    {
        $connection = (new ServiceConnection)
            ->forceFill(['account_number' => "\0=cmd()", 'meter_number' => "\0@evil"]);

        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => 'GW-2026-00008', 'status' => 'paid'])
            ->setRelation('serviceConnection', $connection)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame("'\0=cmd()", $row[1]);
        $this->assertSame("'\0@evil", $row[2]);
    }

    public function test_map_escapes_formula_injection_in_invoice_number(): void
    {
        $invoice = (new Invoice)
            ->forceFill(['invoice_number' => '=cmd()', 'status' => 'paid'])
            ->setRelation('serviceConnection', null)
            ->setRelation('meterReading', null)
            ->setRelation('rateSchedule', null);

        $row = $this->exportFor($invoice)->map($invoice);

        $this->assertSame("'=cmd()", $row[0]);
    }

    public function test_query_clones_the_original_builder_and_orders_by_billing_period_end_desc(): void
    {
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'UQ-00001',
            'meter_number' => 'UM-00001',
        ]);

        $schedule = RateSchedule::factory()->create();

        $endDates = ['2026-07-31', '2026-06-30', '2026-08-31'];

        foreach ($endDates as $index => $end) {
            Invoice::factory()->create([
                'service_connection_id' => $connection->id,
                'meter_reading_id' => MeterReading::factory([
                    'service_connection_id' => $connection->id,
                    'entered_at' => Carbon::parse('2026-08-07')->addDays($index),
                ]),
                'rate_schedule_id' => $schedule->id,
                'billing_period_end' => $end,
            ]);
        }

        $original = Invoice::query()->orderBy('created_at');
        $export = new InvoicesExport($original);

        $obtained = $export->query()->get();

        $this->assertSame(
            ['2026-08-31', '2026-07-31', '2026-06-30'],
            $obtained->pluck('billing_period_end')->map(fn ($date) => $date->toDateString())->all(),
        );

        $this->assertNotSame($original, $export->query());

        $this->assertFalse($original->getQuery()->orders === $export->query()->getQuery()->orders);
    }

    public function test_query_only_selects_invoice_columns_when_input_is_a_relation_query(): void
    {
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'UQ-00002',
            'meter_number' => 'UM-00002',
        ]);

        $schedule = RateSchedule::factory()->create();

        $reader = MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
        ]);

        Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $reader->id,
            'rate_schedule_id' => $schedule->id,
        ]);

        $original = $connection->invoices()->getQuery();
        $export = new InvoicesExport($original);

        $rows = $export->query()->get();

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(Invoice::class, $rows->first());
    }
}