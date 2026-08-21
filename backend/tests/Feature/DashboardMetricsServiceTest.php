<?php

namespace Tests\Feature;

use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
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

    public function test_connections_count_excludes_pending(): void
    {
        ServiceConnection::factory()->count(2)->create(['status' => 'active']);
        ServiceConnection::factory()->count(1)->create(['status' => 'pending']);

        $this->assertSame(2, app(DashboardMetricsService::class)->activeConnectionsCount());
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

    public function test_revenue_this_month_excludes_future_dated_payments(): void
    {
        Payment::factory()->create(['amount' => 300.00, 'paid_at' => now()]);
        Payment::factory()->create(['amount' => 777.00, 'paid_at' => now()->addMonth()->addDay()]);

        $this->assertSame(300.0, app(DashboardMetricsService::class)->revenueThisMonth());
    }

    public function test_revenue_last_months_excludes_future_dated_payments(): void
    {
        $now = now()->startOfMonth();

        Payment::factory()->create(['amount' => 50.00, 'paid_at' => $now]);
        Payment::factory()->create(['amount' => 777.00, 'paid_at' => $now->copy()->addMonth()->addDay()]);

        $series = app(DashboardMetricsService::class)->revenueLastMonths();

        $this->assertSame(50.0, $series[$now->format('Y-m')]);
        $this->assertSame(50.0, array_sum($series));
    }

    public function test_revenue_last_months_counts_slightly_future_payments_in_current_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            Payment::factory()->create(['amount' => 25.00, 'paid_at' => now()->addMinutes(30)]);

            $series = app(DashboardMetricsService::class)->revenueLastMonths();

            $this->assertSame('2026-08', array_key_last($series));
            $this->assertSame(25.0, $series['2026-08']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revenue_last_months_clamps_zero_or_negative_window(): void
    {
        $now = now()->startOfMonth();

        Payment::factory()->create(['amount' => 30.00, 'paid_at' => $now]);

        $series = app(DashboardMetricsService::class)->revenueLastMonths(0);

        $this->assertCount(1, $series);
        $this->assertSame($now->format('Y-m'), array_key_first($series));
        $this->assertSame(30.0, $series[$now->format('Y-m')]);
    }

    public function test_collection_rate_divides_collections_by_billed_in_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            // Billed this month (period ends in August): 1000 total.
            $billedPaid = Invoice::factory()->create([
                'status' => 'paid',
                'total_amount' => 600.00,
                'billing_period_end' => now()->format('Y-m-d'),
            ]);
            Invoice::factory()->create([
                'status' => 'unpaid',
                'total_amount' => 400.00,
                'billing_period_end' => now()->format('Y-m-d'),
            ]);
            // Outside the month — must not count as billed.
            $outside = Invoice::factory()->create([
                'status' => 'unpaid',
                'total_amount' => 999.00,
                'billing_period_end' => now()->subMonth()->format('Y-m-d'),
            ]);

            // Collected this month: 250.
            Payment::factory()->cash()->create([
                'invoice_id' => $billedPaid->id,
                'amount' => 250.00,
                'paid_at' => now(),
            ]);
            Payment::factory()->cash()->create([
                'invoice_id' => $outside->id,
                'amount' => 777.00,
                'paid_at' => now()->subMonth(),
            ]);

            $this->assertSame(25.0, app(DashboardMetricsService::class)->collectionRateForMonth());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_collection_rate_is_null_when_nothing_billed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            // Only a payment, no invoice billed this month.
            $invoice = Invoice::factory()->create([
                'status' => 'paid',
                'total_amount' => 100.00,
                'billing_period_end' => now()->subMonth()->format('Y-m-d'),
            ]);
            Payment::factory()->cash()->create([
                'invoice_id' => $invoice->id,
                'amount' => 100.00,
                'paid_at' => now(),
            ]);

            $this->assertNull(app(DashboardMetricsService::class)->collectionRateForMonth());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revenue_delta_compares_this_month_to_last_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            Payment::factory()->create(['amount' => 150.00, 'paid_at' => now()]);
            Payment::factory()->create(['amount' => 100.00, 'paid_at' => now()->subMonth()]);

            $this->assertSame(50.0, app(DashboardMetricsService::class)->revenueDelta());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revenue_delta_returns_zero_when_last_month_had_no_revenue(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            Payment::factory()->create(['amount' => 50.00, 'paid_at' => now()]);

            $this->assertSame(0.0, app(DashboardMetricsService::class)->revenueDelta());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unpaid_delta_measures_open_bill_count_change(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            // Previous billing month (July): 4 open. Current (August): 5 open → +25%.
            Invoice::factory()->count(4)->create([
                'status' => 'unpaid',
                'billing_period_end' => now()->startOfMonth()->subMonth()->format('Y-m-d'),
            ]);
            Invoice::factory()->count(5)->create([
                'status' => 'unpaid',
                'billing_period_end' => now()->format('Y-m-d'),
            ]);
            // Outside the two compared months — must not affect the delta.
            Invoice::factory()->count(50)->create([
                'status' => 'unpaid',
                'billing_period_end' => now()->subMonths(3)->format('Y-m-d'),
            ]);

            $this->assertSame(25.0, app(DashboardMetricsService::class)->unpaidDelta());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_needs_attention_collects_all_categories(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            $overdue = Invoice::factory()->create([
                'status' => 'overdue',
                'total_amount' => 250.50,
                'due_date' => now()->subDays(5)->format('Y-m-d'),
            ]);
            $pending = ServiceConnection::factory()->create(['status' => 'pending']);
            $lowStock = InventoryItem::factory()->create([
                'name' => 'Lion PVC Pipe',
                'quantity_on_hand' => 2,
                'reorder_level' => 10,
            ]);
            $failedRun = BillingRun::create([
                'period_end' => now()->subMonth()->format('Y-m-d'),
                'status' => 'failed',
                'report' => [],
            ]);

            $data = app(DashboardMetricsService::class)->needsAttention();

            $this->assertCount(1, $data['overdue']);
            $this->assertSame((float) $overdue->total_amount, (float) $data['overdue']->first()['amount']);
            $this->assertSame($overdue->id, $data['overdue']->first()['invoice_id']);

            $this->assertCount(1, $data['pending_connections']);
            $this->assertSame($pending->id, $data['pending_connections']->first()['connection_id']);

            $this->assertCount(1, $data['low_stock']);
            $this->assertSame('low_stock', $data['low_stock']->first()['status']);
            $this->assertSame($lowStock->id, $data['low_stock']->first()['item_id']);

            $this->assertCount(1, $data['billing_runs']);
            $this->assertSame($failedRun->id, $data['billing_runs']->first()['run_id']);
            $this->assertSame('failed', $data['billing_runs']->first()['status']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_needs_attention_empty_database(): void
    {
        $data = app(DashboardMetricsService::class)->needsAttention();

        $this->assertTrue($data['overdue']->isEmpty());
        $this->assertTrue($data['pending_connections']->isEmpty());
        $this->assertTrue($data['low_stock']->isEmpty());
        $this->assertTrue($data['billing_runs']->isEmpty());
        $this->assertSame(0, $data['unread_count']);
    }

    public function test_needs_attention_excludes_completed_billing_runs(): void
    {
        BillingRun::create([
            'period_end' => now()->subMonth()->format('Y-m-d'),
            'status' => 'completed',
            'report' => [],
            'finished_at' => now(),
        ]);

        $this->assertTrue(app(DashboardMetricsService::class)->needsAttention()['billing_runs']->isEmpty());
    }
}
