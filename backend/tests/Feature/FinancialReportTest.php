<?php

namespace Tests\Feature;

use App\Exports\FinancialReportExport;
use App\Filament\Pages\FinancialReport;
use App\Models\Invoice;
use App\Models\Payment;
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

    /**
     * Two invoices billed this month (one unpaid/current, one overdue with a
     * ₱50 penalty), one paid last month, and two payments (one this month,
     * one last month).
     */
    private function seedReportData(): void
    {
        Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => 100.00,
            'penalty_amount' => 0,
            'billing_period_end' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        Invoice::factory()->create([
            'status' => 'overdue',
            'total_amount' => 250.50,
            'penalty_amount' => 50.00,
            'billing_period_end' => now()->toDateString(),
            'due_date' => now()->subDays(45)->toDateString(),
        ]);
        $paid = Invoice::factory()->create([
            'status' => 'paid',
            'total_amount' => 9999.00,
            'billing_period_end' => now()->subMonth()->toDateString(),
        ]);

        Payment::factory()->cash()->create(['invoice_id' => $paid->id, 'amount' => 300.00, 'paid_at' => now()]);
        Payment::factory()->paymongo()->create(['invoice_id' => $paid->id, 'amount' => 120.25, 'paid_at' => now()->subMonth()]);
    }

    public function test_service_builds_accounting_dataset_with_default_month_range(): void
    {
        $this->seedReportData();

        $report = app(FinancialReportService::class)->build();

        $this->assertSame(350.50, $report['summary']['total_receivables']);
        $this->assertSame(300.00, $report['summary']['total_collections']);
        $this->assertSame(now()->startOfMonth()->toDateString(), $report['range']['from']->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $report['range']['to']->toDateString());

        $this->assertCount(4, $report['aging']);
        $this->assertSame(1, $report['aging']->firstWhere('key', 'current')['count']);
        $this->assertSame(100.00, $report['aging']->firstWhere('key', 'current')['amount']);
        $this->assertSame(1, $report['aging']->firstWhere('key', 'd31_60')['count']);
        $this->assertSame(250.50, $report['aging']->firstWhere('key', 'd31_60')['amount']);
        // aging total always equals total receivables
        $this->assertSame($report['summary']['total_receivables'], (float) $report['aging']->sum('amount'));

        $this->assertSame(350.50, $report['income']['gross_billed']);
        $this->assertSame(300.00, $report['income']['cash_collections']);
        $this->assertSame(50.00, $report['income']['misc_income']);
        $this->assertSame(0.0, $report['income']['reconnection_fees']);
        $this->assertSame(0.0, $report['income']['setup_fees']);
        $this->assertSame(100.50, $report['income']['net_operating_income']);
    }

    public function test_aging_buckets_honor_day_boundaries(): void
    {
        foreach ([30 => 'current', 31 => 'd31_60', 60 => 'd31_60', 61 => 'd61_90', 90 => 'd61_90', 91 => 'overdue90'] as $days => $key) {
            Invoice::factory()->create([
                'status' => 'unpaid',
                'total_amount' => 10.00,
                'due_date' => now()->subDays($days)->toDateString(),
            ]);
        }
        // not-yet-due invoices also count as Current
        Invoice::factory()->create([
            'status' => 'unpaid',
            'total_amount' => 10.00,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $counts = app(FinancialReportService::class)->agingBuckets()->pluck('count', 'key');

        $this->assertSame(2, $counts['current']);
        $this->assertSame(2, $counts['d31_60']);
        $this->assertSame(2, $counts['d61_90']);
        $this->assertSame(1, $counts['overdue90']);
    }

    public function test_aging_penalties_sum_stored_penalty_amount_per_bucket(): void
    {
        Invoice::factory()->create([
            'status' => 'overdue',
            'total_amount' => 100.00,
            'penalty_amount' => 12.00,
            'due_date' => now()->subDays(10)->toDateString(),
        ]);
        Invoice::factory()->create([
            'status' => 'overdue',
            'total_amount' => 200.00,
            'penalty_amount' => 24.00,
            'due_date' => now()->subDays(20)->toDateString(),
        ]);
        Invoice::factory()->create([
            'status' => 'overdue',
            'total_amount' => 300.00,
            'penalty_amount' => 36.00,
            'due_date' => now()->subDays(70)->toDateString(),
        ]);

        $aging = app(FinancialReportService::class)->agingBuckets();

        $this->assertSame(36.00, $aging->firstWhere('key', 'current')['penalty']);
        $this->assertSame(0.0, $aging->firstWhere('key', 'd31_60')['penalty']);
        $this->assertSame(36.00, $aging->firstWhere('key', 'd61_90')['penalty']);
        $this->assertSame(72.00, $aging->sum('penalty'));
    }

    public function test_range_normalization_clamps_and_swaps(): void
    {
        $service = app(FinancialReportService::class);

        $range = $service->normalizeRange('2026-03-10', '2026-03-05');
        $this->assertSame('2026-03-05', $range['from']->toDateString());
        $this->assertSame('2026-03-10', $range['to']->toDateString());

        $range = $service->normalizeRange(null, null);
        $this->assertSame(now()->startOfMonth()->toDateString(), $range['from']->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $range['to']->toDateString());

        $range = $service->normalizeRange('not-a-date', null);
        $this->assertSame(now()->startOfMonth()->toDateString(), $range['from']->toDateString());
    }

    public function test_page_renders_accounting_sections_for_admin(): void
    {
        $this->seedReportData();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/financial-report')
            ->assertOk()
            ->assertSee('Accounting & Financial Management')
            ->assertSee('Reporting period')
            ->assertSee('Receivables vs collections')
            ->assertSee('Accounts receivable aging')
            ->assertSee('Statement of income')
            ->assertSee('Payment breakdown')
            ->assertDontSee('Active customers')
            ->assertDontSee('Revenue by month');
    }

    public function test_page_requires_admin_auth(): void
    {
        $this->get('/admin/financial-report')->assertRedirectToRoute('filament.admin.auth.login');
    }

    public function test_excel_export_has_four_accounting_sheets(): void
    {
        $this->seedReportData();

        $sheets = (new FinancialReportExport(
            app(FinancialReportService::class),
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ))->sheets();

        $this->assertCount(4, $sheets);
        $this->assertSame('Summary', $sheets[0]->title());
        $this->assertSame('AR Aging', $sheets[1]->title());
        $this->assertSame('Income Statement', $sheets[2]->title());
        $this->assertSame('Payments Ledger', $sheets[3]->title());

        $summaryRows = $sheets[0]->array();
        $this->assertSame('Total receivables (PHP)', $summaryRows[2][0]);
        $this->assertSame('350.50', $summaryRows[2][1]);
        $this->assertSame('300.00', $summaryRows[3][1]);

        $agingRows = $sheets[1]->array();
        $this->assertCount(4, $agingRows);
        $this->assertSame('Current (0–30 days)', $agingRows[0][0]);
        $this->assertSame(1, $agingRows[0][1]);

        $incomeRows = $sheets[2]->array();
        $this->assertSame('Gross billed revenue', $incomeRows[0][0]);
        $this->assertSame('350.50', $incomeRows[0][1]);
        $this->assertSame('100.50', $incomeRows[5][1]);

        $ledgerRows = $sheets[3]->query()->get();
        $this->assertCount(1, $ledgerRows);
        $this->assertSame(300.0, (float) $ledgerRows->first()->amount);
    }

    public function test_pdf_template_renders_accounting_sections(): void
    {
        $this->seedReportData();

        $data = app(FinancialReportService::class)->build();
        $range = app(FinancialReportService::class)->normalizeRange(null, null);
        $data['ledger'] = app(FinancialReportService::class)->ledgerRows($range['from'], $range['to']);

        $html = view('pdfs.financial-report', $data)->render();

        $this->assertStringContainsString('GUINOBATAN WATERWORKS', $html);
        $this->assertStringContainsString('Accounts Receivable Aging', $html);
        $this->assertStringContainsString('Statement of Income', $html);
        $this->assertStringContainsString('Payment Breakdown', $html);
        $this->assertStringContainsString('300.00', $html);
    }

    public function test_excel_and_pdf_header_actions_are_registered(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(FinancialReport::class)
            ->assertActionExists('exportExcel')
            ->assertActionExists('exportPdf');
    }

    public function test_ledger_table_filters_by_payment_method(): void
    {
        $invoice = Invoice::factory()->paid()->create();
        $cash = Payment::factory()->cash()->create(['invoice_id' => $invoice->id, 'amount' => 100.00]);
        $online = Payment::factory()->paymongo()->create(['invoice_id' => $invoice->id, 'amount' => 200.00]);

        Livewire::withQueryParams([
            'filters' => json_encode(['method' => ['value' => 'cash']]),
        ])->actingAs($this->admin(), 'admin')
            ->test(FinancialReport::class)
            ->assertCanSeeTableRecords([$cash])
            ->assertCanNotSeeTableRecords([$online]);
    }

    public function test_sidebar_label_and_sort(): void
    {
        $this->assertSame('Accounting & Finance', FinancialReport::getNavigationLabel());
        $this->assertSame(100, FinancialReport::getNavigationSort());
    }
}
