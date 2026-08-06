<?php

namespace Tests\Feature;

use App\Filament\Resources\BillingRunResource\Pages\ListBillingRuns;
use App\Filament\Resources\BillingRunResource\Pages\ViewBillingRun;
use App\Jobs\RunBillingJob;
use App\Models\BillingRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class BillingRunResourceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function billingRun(array $attributes = []): BillingRun
    {
        return BillingRun::create([
            'period_end' => '2026-06-30',
            'status' => 'completed',
            'report' => [
                [
                    'account_number' => 'ACCT-1',
                    'status' => 'billed',
                    'reason' => null,
                    'invoice_number' => 'GW-2026-00001',
                    'total_amount' => 123.45,
                ],
                [
                    'account_number' => 'ACCT-2',
                    'status' => 'skipped',
                    'reason' => 'No reading in the billing period (2026-06-01 to 2026-06-30).',
                    'invoice_number' => null,
                    'total_amount' => null,
                ],
            ],
            'finished_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_list_renders_runs_with_status_badges(): void
    {
        $completed = $this->billingRun();
        $failed = $this->billingRun(['status' => 'failed', 'error' => 'Something broke.']);
        $running = $this->billingRun(['status' => 'running', 'report' => null, 'finished_at' => null]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->assertCanSeeTableRecords([$completed, $failed, $running])
            ->assertSee('Completed')
            ->assertSee('Failed')
            ->assertSee('Running');
    }

    public function test_run_billing_action_visible(): void
    {
        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->assertActionVisible('runBilling');
    }

    public function test_run_billing_defaults_to_last_month_end(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->callAction('runBilling', data: [])
            ->assertNotified('Billing run dispatched');

        $defaultPeriod = date('Y-m-d', strtotime('last day of previous month'));
        $run = BillingRun::first();

        $this->assertNotNull($run);
        $this->assertSame('running', $run->status);
        $this->assertSame($defaultPeriod, $run->period_end->toDateString());

        Queue::assertPushed(RunBillingJob::class, fn (RunBillingJob $job) => $job->periodEnd === $defaultPeriod && $job->billingRunId === $run->id);
    }

    public function test_run_billing_uses_explicit_period(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->callAction('runBilling', data: ['period' => '2026-06-30', 'force' => false])
            ->assertNotified('Billing run dispatched');

        $run = BillingRun::first();

        $this->assertNotNull($run);
        $this->assertSame('2026-06-30', $run->period_end->toDateString());

        Queue::assertPushed(RunBillingJob::class, fn (RunBillingJob $job) => $job->periodEnd === '2026-06-30' && $job->billingRunId === $run->id);
    }

    public function test_run_billing_blocked_by_active_run_for_period(): void
    {
        Queue::fake();
        $active = $this->billingRun(['status' => 'running', 'report' => null, 'finished_at' => null]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->callAction('runBilling', data: ['period' => '2026-06-30', 'force' => false])
            ->assertNotified('Billing run blocked');

        $this->assertSame(1, BillingRun::count());
        $this->assertSame('running', $active->fresh()->status);
        Queue::assertNotPushed(RunBillingJob::class);
    }

    public function test_run_billing_stale_run_requires_force(): void
    {
        Queue::fake();
        $stale = $this->billingRun(['status' => 'running', 'report' => null, 'finished_at' => null]);
        $stale->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->callAction('runBilling', data: ['period' => '2026-06-30', 'force' => false])
            ->assertNotified('Billing run blocked');

        $this->assertSame(1, BillingRun::count());
        $this->assertSame('running', $stale->fresh()->status);
        Queue::assertNotPushed(RunBillingJob::class);
    }

    public function test_run_billing_force_abandons_stale_run_and_starts_fresh(): void
    {
        Queue::fake();
        $stale = $this->billingRun(['status' => 'running', 'report' => null, 'finished_at' => null]);
        $stale->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ListBillingRuns::class)
            ->callAction('runBilling', data: ['period' => '2026-06-30', 'force' => true])
            ->assertNotified('Billing run dispatched');

        $this->assertSame(2, BillingRun::count());

        $abandoned = $stale->fresh();
        $this->assertSame('failed', $abandoned->status);
        $this->assertStringContainsString('forced failed', $abandoned->error);

        $fresh = BillingRun::where('id', '!=', $stale->id)->first();
        $this->assertSame('running', $fresh->status);

        Queue::assertPushed(RunBillingJob::class, fn (RunBillingJob $job) => $job->periodEnd === '2026-06-30' && $job->billingRunId === $fresh->id);
    }

    public function test_view_run_shows_summary_and_report_rows(): void
    {
        $run = $this->billingRun();

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewBillingRun::class, ['record' => $run->id])
            ->assertSee('ACCT-1')
            ->assertSee('GW-2026-00001')
            ->assertSee('123.45')
            ->assertSee('ACCT-2')
            ->assertSee('No reading in the billing period')
            ->assertSee('Completed')
            ->assertSee('Invoices billed');
    }

    public function test_view_run_shows_error_for_failed_run(): void
    {
        $run = $this->billingRun(['status' => 'failed', 'error' => 'Database connection lost.', 'report' => null, 'finished_at' => now()]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewBillingRun::class, ['record' => $run->id])
            ->assertSee('Database connection lost.');
    }

    public function test_view_run_shows_placeholder_for_empty_report(): void
    {
        $run = $this->billingRun(['report' => []]);

        Livewire::actingAs($this->admin(), 'admin')
            ->test(ViewBillingRun::class, ['record' => $run->id])
            ->assertSee('No report rows');
    }
}
