<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Services\DashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMetricsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_returns_zeroes(): void
    {
        $service = app(DashboardMetricsService::class);

        $this->assertSame(0, $service->activeConnectionsCount());
        $this->assertSame(0, $service->unpaidInvoicesCount());
        $this->assertSame(0, $service->overdueInvoicesCount());
        $this->assertSame(0.0, $service->receivablesOutstanding());
        $this->assertSame(0.0, $service->revenueThisMonth());
    }

    public function test_connections_count_only_active(): void
    {
        ServiceConnection::factory()->count(3)->create(['status' => 'active']);
        ServiceConnection::factory()->count(2)->create(['status' => 'disconnected']);

        $this->assertSame(3, app(DashboardMetricsService::class)->activeConnectionsCount());
    }

    public function test_invoice_counts_split_by_status(): void
    {
        Invoice::factory()->count(4)->create(['status' => 'unpaid']);
        Invoice::factory()->count(2)->create(['status' => 'overdue']);
        Invoice::factory()->count(5)->create(['status' => 'paid']);

        $service = app(DashboardMetricsService::class);

        $this->assertSame(4, $service->unpaidInvoicesCount());
        $this->assertSame(2, $service->overdueInvoicesCount());
    }

    public function test_receivables_outstanding_sums_unpaid_and_overdue_only(): void
    {
        Invoice::factory()->create(['status' => 'unpaid', 'total_amount' => 100.00]);
        Invoice::factory()->create(['status' => 'overdue', 'total_amount' => 250.50]);
        Invoice::factory()->create(['status' => 'paid', 'total_amount' => 9999.00]);

        $this->assertSame(350.5, app(DashboardMetricsService::class)->receivablesOutstanding());
    }

    public function test_revenue_this_month_counts_online_and_offline_payments_by_paid_at(): void
    {
        Payment::factory()->cash()->create(['amount' => 300.00, 'paid_at' => now()]);
        Payment::factory()->paymongo()->create(['amount' => 120.25, 'paid_at' => now()]);
        Payment::factory()->create(['amount' => 777.00, 'paid_at' => now()->subMonth()]);

        $this->assertSame(420.25, app(DashboardMetricsService::class)->revenueThisMonth());
    }

    public function test_revenue_last_months_returns_zero_filled_series_with_newest_last(): void
    {
        $now = now()->startOfMonth();

        Payment::factory()->create(['amount' => 50.00, 'paid_at' => $now]);
        Payment::factory()->create(['amount' => 20.00, 'paid_at' => $now->copy()->subMonths(3)->addDays(5)]);

        $series = app(DashboardMetricsService::class)->revenueLastMonths();

        $this->assertCount(6, $series);
        $this->assertSame($now->format('Y-m'), array_key_last($series));
        $this->assertSame($now->copy()->subMonths(5)->format('Y-m'), array_key_first($series));
        $this->assertSame(50.0, $series[$now->format('Y-m')]);
        $this->assertSame(20.0, $series[$now->copy()->subMonths(3)->format('Y-m')]);
        $this->assertSame(0.0, $series[$now->copy()->subMonths(1)->format('Y-m')]);
    }
}
