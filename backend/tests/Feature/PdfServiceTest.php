<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Services\BillingService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(): Invoice
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $schedule = RateSchedule::factory()->create([
            'name' => 'Standard Flat Rate',
            'type' => 'flat',
            'flat_rate' => 10.00,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Maria Santos',
            'barangay_id' => $barangay->id,
            'address' => '123 Rizal St.',
            'status' => 'active',
            'rate_schedule_id' => $schedule->id,
        ]);
        $reading = MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 220.00,
            'previous_reading' => 120.00,
            'cu_m_used' => 100.00,
            'entered_at' => '2026-07-15 08:00:00',
            'flagged' => 0,
        ]);

        return Invoice::create([
            'invoice_number' => 'GW-2026-00003',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $reading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-07-01',
            'billing_period_end' => '2026-07-31',
            'previous_balance' => 150.00,
            'base_amount' => 1000.00,
            'penalty_amount' => 3.00,
            'total_amount' => 1153.00,
            'due_date' => '2026-08-15',
            'status' => 'unpaid',
        ]);
    }

    public function test_view_renders_every_itemized_field(): void
    {
        $invoice = $this->makeInvoice();
        $data = app(PdfService::class)->buildViewData($invoice);

        $html = view('pdfs.invoice', $data)->render();

        // Header / identity
        $this->assertStringContainsString('GUINOBATAN WATERWORKS', $html);
        $this->assertStringContainsString('GW-2026-00003', $html);
        $this->assertStringContainsString('GW-00001', $html);   // account number
        $this->assertStringContainsString('MTR-00001', $html);  // meter number
        $this->assertStringContainsString('Maria Santos', $html);
        $this->assertStringContainsString('123 Rizal St.', $html);
        $this->assertStringContainsString('Poblacion', $html);

        // Consumption
        $this->assertStringContainsString('220', $html);
        $this->assertStringContainsString('120', $html);
        $this->assertStringContainsString('100.00 cu.m.', $html);
        $this->assertStringContainsString('10.00 / cu.m.', $html);

        // Breakdown line items
        $this->assertStringContainsString('Current Charges', $html);
        $this->assertStringContainsString('Arrears', $html);
        $this->assertStringContainsString('Penalty', $html);
        $this->assertStringContainsString('Total Amount Due', $html);

        // Period / dates
        $this->assertStringContainsString('Jul 01, 2026', $html);
        $this->assertStringContainsString('Jul 31, 2026', $html);
        $this->assertStringContainsString('Aug 15, 2026', $html);

        // Itemized amounts (formatted two-decimal)
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('150.00', $html);
        $this->assertStringContainsString('3.00', $html);
        $this->assertStringContainsString('1,153.00', $html);
    }

    public function test_generate_returns_a_valid_pdf_string(): void
    {
        $invoice = $this->makeInvoice();
        $pdf = app(PdfService::class)->generate($invoice);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('PDF-', $pdf);
        $this->assertNotEmpty($pdf);

        // Font subsetting is enabled; a 1-page text-only invoice with full (non-subset)
        // DejaVu fonts was ~880 KB. Without subsetting the file regressed to that size.
        $this->assertLessThan(400_000, strlen($pdf), 'PDF is too large — font subsetting likely disabled.');
    }

    public function test_build_view_data_resolves_relations(): void
    {
        $invoice = $this->makeInvoice();

        $data = app(PdfService::class)->buildViewData($invoice);

        $this->assertSame('Maria Santos', $data['customerName']);
        $this->assertSame('Poblacion', substr($data['addressLine'], -9));
        $this->assertSame('10.00 / cu.m.', $data['rateDisplay']);
        $this->assertSame(1000.00, $data['currentCharges']);
        $this->assertSame(150.00, $data['arrears']);
        $this->assertSame(3.00, $data['penalty']);
        $this->assertSame(1153.00, $data['total']);
    }

    public function test_command_writes_a_pdf_file_to_storage(): void
    {
        Storage::fake('local');
        $invoice = $this->makeInvoice();

        $target = 'pdf-verification/'.$invoice->invoice_number.'.pdf';

        $this->artisan('billing:pdf', ['invoice-number' => $invoice->invoice_number, '--output' => $target])
            ->assertSuccessful()
            ->expectsOutputToContain("Wrote {$invoice->invoice_number} PDF to:");

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($target));
        $this->assertStringStartsWith('%PDF', $disk->get($target));
    }

    public function test_command_rejects_an_unknown_invoice_number(): void
    {
        $this->artisan('billing:pdf', ['invoice-number' => 'GW-2026-NOPE'])
            ->assertFailed()
            ->expectsOutputToContain('Invoice not found: GW-2026-NOPE');
    }

    public function test_pdf_breakdown_matches_a_real_billing_service_invoice_with_arrears_and_penalty(): void
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $schedule = RateSchedule::factory()->create([
            'name' => 'Standard Flat Rate',
            'type' => 'flat',
            'flat_rate' => 10.00,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        PenaltyRule::factory()->create([
            'percent_per_month' => 2.00,
            'grace_period_days' => 15,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-00021',
            'meter_number' => 'MTR-00021',
            'registered_name' => 'Arrears And Penalty',
            'barangay_id' => $barangay->id,
            'address' => 'Lot 5 Brgy. Hall Rd.',
            'status' => 'active',
            'rate_schedule_id' => $schedule->id,
        ]);

        // Month 1 (June): 100 cu.m. x 10.00 = 1,000.00, due 2026-07-15 (period end + 15 grace).
        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 200.00,
            'previous_reading' => 100.00,
            'cu_m_used' => 100.00,
            'entered_at' => '2026-06-15 08:00:00',
            'flagged' => 0,
        ]);
        app(BillingService::class)->run('2026-06-30');

        $juneInvoice = Invoice::where('service_connection_id', $connection->id)->sole();
        $this->assertSame(1000.00, (float) $juneInvoice->base_amount);
        $this->assertSame(1000.00, (float) $juneInvoice->total_amount);

        // Month 2 (August): a fresh 100 cu.m. reading. The June invoice is now overdue
        // (due 2026-07-15 < 2026-08-31), so this invoice carries real arrears + 1 month of
        // compound penalty: 1,000.00 * 2% = 20.00.
        MeterReading::factory()->create([
            'service_connection_id' => $connection->id,
            'present_reading' => 300.00,
            'previous_reading' => 200.00,
            'cu_m_used' => 100.00,
            'entered_at' => '2026-08-20 08:00:00',
            'flagged' => 0,
        ]);
        app(BillingService::class)->run('2026-08-31');

        $augustInvoice = Invoice::where('service_connection_id', $connection->id)
            ->where('invoice_number', '!=', $juneInvoice->invoice_number)
            ->sole();
        $this->assertSame(1000.00, (float) $augustInvoice->base_amount);
        $this->assertSame(1000.00, (float) $augustInvoice->previous_balance);
        $this->assertSame(20.00, (float) $augustInvoice->penalty_amount);
        $this->assertSame(2020.00, (float) $augustInvoice->total_amount);

        $data = app(PdfService::class)->buildViewData($augustInvoice);

        // The itemized PDF lines mirror the stored breakdown exactly...
        $this->assertSame(1000.00, $data['currentCharges']);
        $this->assertSame(1000.00, $data['arrears']);
        $this->assertSame(20.00, $data['penalty']);
        $this->assertSame(2020.00, $data['total']);
        // ...and they sum to the printed total (so the rendered bill never disagrees with itself).
        $this->assertSame(
            $data['total'],
            round($data['currentCharges'] + $data['arrears'] + $data['penalty'], 2),
        );
        $this->assertStringContainsString('Penalty (2.00%/mo on unpaid)', $data['penaltyLabel']);

        $html = view('pdfs.invoice', $data)->render();
        $this->assertStringContainsString('Current Charges', $html);
        $this->assertStringContainsString('Arrears', $html);
        $this->assertStringContainsString('Total Amount Due', $html);
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('2,020.00', $html);

        $pdf = app(PdfService::class)->generate($augustInvoice);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
