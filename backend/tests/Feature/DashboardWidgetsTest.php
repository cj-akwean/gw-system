<?php

namespace Tests\Feature;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ServiceConnectionResource;
use App\Filament\Widgets\MetricsOverview;
use App\Filament\Widgets\RevenueChart;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
