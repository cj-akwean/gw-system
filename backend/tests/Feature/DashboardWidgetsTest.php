<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ServiceConnectionResource;
use App\Filament\Widgets\MetricsOverview;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Filament\Widgets\RecentPaymentsWidget;
use App\Filament\Widgets\RevenueChart;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedDashboardData(): void
    {
        ServiceConnection::factory()->count(2)->create(['status' => 'active']);
        ServiceConnection::factory()->create(['status' => 'disconnected']);

        $unpaid = Invoice::factory()->create(['status' => 'unpaid', 'total_amount' => 100.00]);
        $overdue = Invoice::factory()->create(['status' => 'overdue', 'total_amount' => 250.50]);
        $paid = Invoice::factory()->create(['status' => 'paid', 'total_amount' => 9999.00]);

        Payment::factory()->cash()->create(['invoice_id' => $paid->id, 'amount' => 300.00, 'paid_at' => now()]);
        Payment::factory()->paymongo()->create(['invoice_id' => $paid->id, 'amount' => 120.25, 'paid_at' => now()]);
    }

    public function test_metrics_overview_renders_all_stats(): void
    {
        $this->seedDashboardData();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(MetricsOverview::class)
            ->assertSee('Active customers')
            ->assertSee('Unpaid bills')
            ->assertSee('Overdue bills')
            ->assertSee('Outstanding amount')
            ->assertSee('Revenue this month')
            ->assertSee('₱350.50')
            ->assertSee('₱420.25');
    }

    public function test_metrics_overview_renders_zeroes_on_empty_database(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(MetricsOverview::class)
            ->assertSee('Active customers')
            ->assertSee('Outstanding amount')
            ->assertSee('₱0.00');
    }

    public function test_revenue_chart_renders_with_series(): void
    {
        $this->seedDashboardData();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(RevenueChart::class)
            ->assertSee('Revenue — last 6 months')
            ->assertSee('Revenue');
    }

    public function test_dashboard_page_shows_metrics_to_admin(): void
    {
        $this->seedDashboardData();

        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('MetricsOverview')
            ->assertSee('RevenueChart');
    }

    public function test_stat_cards_deep_link_to_filtered_views(): void
    {
        $html = Livewire::actingAs($this->admin(), 'admin')
            ->test(MetricsOverview::class)
            ->html();

        $this->assertStringContainsString(ServiceConnectionResource::getUrl('index'), $html);
        $this->assertStringContainsString(
            rawurlencode((string) json_encode(['status' => ['value' => 'active']])),
            $html,
        );

        $this->assertStringContainsString(PaymentResource::getUrl('index'), $html);
        $this->assertStringContainsString(
            rawurlencode((string) json_encode([
                'paid_at' => [
                    'paid_from' => now()->startOfMonth()->toDateString(),
                    'paid_until' => now()->endOfMonth()->toDateString(),
                ],
            ])),
            $html,
        );

        $this->assertStringContainsString(InvoiceResource::getUrl('index'), $html);
        $this->assertStringContainsString(
            rawurlencode((string) json_encode(['status' => ['values' => ['unpaid']]])),
            $html,
        );
        $this->assertStringContainsString(
            rawurlencode((string) json_encode(['status' => ['values' => ['overdue']]])),
            $html,
        );
        $this->assertStringContainsString(
            rawurlencode((string) json_encode(['status' => ['values' => ['unpaid', 'overdue']]])),
            $html,
        );
    }

    public function test_metrics_overview_shows_collection_rate_and_aging_when_data_exists(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 15, 12, 0, 0));

        try {
            $paid = Invoice::factory()->create([
                'status' => 'paid',
                'total_amount' => 600.00,
                'billing_period_end' => now()->format('Y-m-d'),
                'due_date' => now()->addDays(10)->format('Y-m-d'),
            ]);
            Invoice::factory()->create([
                'status' => 'overdue',
                'total_amount' => 900.00,
                'billing_period_end' => now()->format('Y-m-d'),
                'due_date' => now()->subDays(100)->format('Y-m-d'),
            ]);
            Payment::factory()->cash()->create([
                'invoice_id' => $paid->id,
                'amount' => 150.00,
                'paid_at' => now(),
            ]);

            Livewire::actingAs($this->admin(), 'admin')
                ->test(MetricsOverview::class)
                ->assertSee('Collection rate')
                ->assertSee('Receivables 90+ days');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_needs_attention_widget_renders_categories_and_empty_state(): void
    {
        // Empty DB → friendly empty state.
        Livewire::actingAs($this->admin(), 'admin')
            ->test(NeedsAttentionWidget::class)
            ->assertSee('Nothing needs your attention right now.');

        // Seeded → categories render with links.
        Invoice::factory()->create(['status' => 'overdue', 'total_amount' => 250.50]);
        ServiceConnection::factory()->create(['status' => 'pending']);
        InventoryItem::factory()->create([
            'name' => 'Lion PVC Pipe',
            'quantity_on_hand' => 2,
            'reorder_level' => 10,
        ]);
        BillingRun::create([
            'period_end' => now()->subMonth()->format('Y-m-d'),
            'status' => 'failed',
            'report' => [],
        ]);

        $html = Livewire::actingAs($this->admin(), 'admin')
            ->test(NeedsAttentionWidget::class)
            ->assertSee('Overdue bills')
            ->assertSee('Pending connections')
            ->assertSee('Low stock')
            ->assertSee('Billing runs')
            ->html();

        $this->assertStringContainsString(InvoiceResource::getUrl('index'), $html);
        $this->assertStringContainsString(ServiceConnectionResource::getUrl('index'), $html);
    }

    public function test_recent_payments_widget_lists_latest_payments(): void
    {
        $this->seedDashboardData();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(RecentPaymentsWidget::class)
            ->assertSee('₱300.00')
            ->assertSee('₱120.25');
    }
}
