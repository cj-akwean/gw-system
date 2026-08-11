<?php

namespace Tests\Feature;

use App\Exports\FinancialReportExport;
use App\Filament\Pages\FinancialReport;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedReportData(): void
    {
        ServiceConnection::factory()->count(2)->create(['status' => 'active']);

        $unpaid = Invoice::factory()->create(['status' => 'unpaid', 'total_amount' => 100.00]);
        $overdue = Invoice::factory()->create(['status' => 'overdue', 'total_amount' => 250.50]);
        $paid = Invoice::factory()->create(['status' => 'paid', 'total_amount' => 9999.00]);

        Payment::factory()->cash()->create(['invoice_id' => $paid->id, 'amount' => 300.00, 'paid_at' => now()]);
        Payment::factory()->paymongo()->create([
            'invoice_id' => $paid->id,
            'amount' => 120.25,
            'paid_at' => now()->subMonth(),
        ]);
    }

    public function test_service_builds_summary_and_zero_filled_months(): void
    {
        $this->seedReportData();

        $report = app(FinancialReportService::class)->build();

        $this->assertGreaterThanOrEqual(2, $report['summary']['active_connections']);
        $this->assertSame(1, $report['summary']['unpaid_bills']);
        $this->assertSame(1, $report['summary']['overdue_bills']);
        $this->assertSame(350.50, $report['summary']['outstanding_amount']);
        $this->assertSame(300.00, $report['summary']['revenue_this_month']);
        $this->assertCount(12, $report['monthlyRevenue']);
        $this->assertArrayHasKey(now()->format('Y-m'), $report['monthlyRevenue']);
        $this->assertSame(300.0, $report['monthlyRevenue'][now()->format('Y-m')]);
    }

    public function test_page_renders_summary_and_monthly_table_for_admin(): void
    {
        $this->seedReportData();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/financial-report')
            ->assertOk()
            ->assertSee('Financial Report')
            ->assertSee('Active customers')
            ->assertSee('Revenue this month')
            ->assertSee('Revenue by month')
            ->assertSee(now()->format('M Y'))
            ->assertSee('300.00');
    }

    public function test_page_requires_admin_auth(): void
    {
        $this->get('/admin/financial-report')->assertRedirectToRoute('filament.admin.auth.login');
    }

    public function test_excel_export_has_summary_and_revenue_sheets(): void
    {
        $this->seedReportData();

        $export = new FinancialReportExport(app(FinancialReportService::class));
        $sheets = $export->sheets();

        $this->assertCount(2, $sheets);

        $summaryRows = $sheets[0]->array();
        $this->assertSame('Active customers', $summaryRows[1][0]);
        $this->assertGreaterThanOrEqual(2, $summaryRows[1][1]);
        $this->assertSame('Revenue this month (PHP)', $summaryRows[5][0]);

        $revenueRows = $sheets[1]->array();
        $this->assertSame(now()->format('M Y'), $revenueRows[count($revenueRows) - 1][0]);
    }

    public function test_pdf_template_renders_branded_report(): void
    {
        $this->seedReportData();

        $data = app(FinancialReportService::class)->build();
        $html = view('pdfs.financial-report', $data)->render();

        $this->assertStringContainsString('GUINOBATAN WATERWORKS', $html);
        $this->assertStringContainsString('Revenue by Month', $html);
        $this->assertStringContainsString(now()->format('M Y'), $html);
        $this->assertStringContainsString('300.00', $html);
    }

    public function test_excel_and_pdf_header_actions_are_registered(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(FinancialReport::class)
            ->assertActionExists('exportExcel')
            ->assertActionExists('exportPdf');
    }
}
