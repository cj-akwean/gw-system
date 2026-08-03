<?php

namespace Tests\Feature;

use App\Jobs\RunBillingJob;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\RateTier;
use App\Models\ServiceConnection;
use App\Services\BillingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedPenaltyRule(): PenaltyRule
    {
        return PenaltyRule::create([
            'percent_per_month' => 2.00,
            'grace_period_days' => 15,
            'disconnection_after_days' => 60,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function flatSchedule(float $rate = 10.00): RateSchedule
    {
        return RateSchedule::create([
            'name' => 'Test Flat Rate',
            'type' => 'flat',
            'flat_rate' => $rate,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    private function reading(int $connectionId, float $used, string $date, int $flagged = 0): MeterReading
    {
        return MeterReading::factory()->create([
            'service_connection_id' => $connectionId,
            'present_reading' => 120.00 + $used,
            'previous_reading' => 120.00,
            'cu_m_used' => $used,
            'entered_at' => $date,
            'flagged' => $flagged,
        ]);
    }

    public function test_flat_rate_computes_usage_times_rate(): void
    {
        $service = new BillingService;
        $schedule = $this->flatSchedule(10.00);

        $this->assertSame(1005.00, $service->computeBaseAmount(100.50, $schedule));
    }

    public function test_tiered_rate_sums_usage_blocks(): void
    {
        $service = new BillingService;
        $schedule = RateSchedule::factory()->tiered()->create(['effective_from' => '2026-01-01']);

        RateTier::create(['rate_schedule_id' => $schedule->id, 'min_cu_m' => 0, 'max_cu_m' => 10, 'rate_per_cu_m' => 8.00]);
        RateTier::create(['rate_schedule_id' => $schedule->id, 'min_cu_m' => 10, 'max_cu_m' => 20, 'rate_per_cu_m' => 10.00]);
        RateTier::create(['rate_schedule_id' => $schedule->id, 'min_cu_m' => 20, 'max_cu_m' => null, 'rate_per_cu_m' => 12.00]);

        $this->assertSame(240.00, $service->computeBaseAmount(25.00, $schedule));
    }

    public function test_connection_schedule_wins_over_global(): void
    {
        $service = new BillingService;
        $global = $this->flatSchedule(10.00);
        $own = $this->flatSchedule(5.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $own->id]);

        $found = $service->findEffectiveSchedule($connection->id, '2026-07-31');

        $this->assertSame($own->id, $found->id);
        $this->assertNotSame($global->id, $found->id);
    }

    public function test_global_schedule_is_fallback_when_connection_has_none(): void
    {
        $service = new BillingService;
        $global = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => null]);

        $found = $service->findEffectiveSchedule($connection->id, '2026-07-31');

        $this->assertSame($global->id, $found->id);
    }

    public function test_penalty_is_zero_during_grace_period(): void
    {
        $service = new BillingService;
        $rule = $this->seedPenaltyRule();

        $this->assertSame(0.0, $service->computePenalty(1000.00, '2026-06-01', '2026-06-10', $rule));
    }

    public function test_penalty_is_2_percent_per_full_month_after_grace(): void
    {
        $service = new BillingService;
        $rule = $this->seedPenaltyRule();

        $this->assertSame(40.00, $service->computePenalty(1000.00, '2026-06-01', '2026-08-16', $rule));
    }

    public function test_bill_connection_creates_clean_invoice(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 120.00, '2026-07-30');

        $invoice = $service->billConnection($connection, $reading, $schedule, '2026-07-01', '2026-07-31');

        $this->assertSame('unpaid', $invoice->status);
        $this->assertSame(0.0, (float) $invoice->previous_balance);
        $this->assertSame(0.0, (float) $invoice->penalty_amount);
        $this->assertSame(1200.00, (float) $invoice->base_amount);
        $this->assertSame(1200.00, (float) $invoice->total_amount);
        $this->assertSame('2026-08-15', $invoice->due_date->toDateString());
        $this->assertSame($reading->id, $invoice->meter_reading_id);
        $this->assertSame($schedule->id, $invoice->rate_schedule_id);
    }

    public function test_bill_connection_carries_arrears_and_accrued_penalty(): void
    {
        $service = new BillingService;
        $rule = $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 120.00, '2026-07-30');
        $oldReading = $this->reading($connection->id, 100.00, '2026-06-30');

        Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $oldReading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'previous_balance' => 0,
            'base_amount' => 1000.00,
            'penalty_amount' => 0,
            'total_amount' => 1000.00,
            'due_date' => '2026-06-01',
            'status' => 'unpaid',
        ]);

        $invoice = $service->billConnection($connection, $reading, $schedule, '2026-07-01', '2026-07-31', $rule);

        $this->assertSame(1000.00, (float) $invoice->previous_balance);
        $this->assertSame(20.00, (float) $invoice->penalty_amount);
        $this->assertSame(1200.00, (float) $invoice->base_amount);
        $this->assertSame(2220.00, (float) $invoice->total_amount);
    }

    public function test_run_bills_connection_with_reading_in_period(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('billed', $row['status']);
        $this->assertSame(750.00, $row['total_amount']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_run_skips_flagged_reading_and_never_bills_negative_usage(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, -50.00, '2026-07-30', 2);

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Flagged reading', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_skips_connection_without_reading(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('No reading', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_skips_reading_outside_period_window(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-06-01');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('No reading', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_is_idempotent(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $service->run('2026-07-31');
        $second = $service->run('2026-07-31');
        $row = $second->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Already billed', $row['reason']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_run_marks_past_due_unpaid_invoices_as_overdue(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 75.00, '2026-07-30');

        $old = Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $this->reading($connection->id, 50.00, '2026-06-30')->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'previous_balance' => 0,
            'base_amount' => 500.00,
            'penalty_amount' => 0,
            'total_amount' => 500.00,
            'due_date' => '2026-07-01',
            'status' => 'unpaid',
        ]);

        $service->run('2026-07-31');

        $this->assertSame('overdue', $old->fresh()->status);
    }

    public function test_run_skips_inactive_connections(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id, 'status' => 'inactive']);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $report = $service->run('2026-07-31');

        $this->assertTrue($report->where('account_number', $connection->account_number)->isEmpty());
        $this->assertSame(0, Invoice::count());
    }

    public function test_invoice_number_sequences_after_last(): void
    {
        $service = new BillingService;
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 75.00, '2026-07-30');

        Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $reading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'previous_balance' => 0,
            'base_amount' => 500.00,
            'penalty_amount' => 0,
            'total_amount' => 500.00,
            'due_date' => '2026-07-01',
            'status' => 'unpaid',
        ]);

        $this->assertSame('GW-2026-00002', $service->generateInvoiceNumber());
    }

    public function test_invoice_number_does_not_collide_after_9_invoices(): void
    {
        $service = new BillingService;
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 75.00, '2026-07-30');

        for ($i = 1; $i <= 11; $i++) {
            $invoiceReading = $this->reading($connection->id, 75.00, '2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));

            Invoice::create([
                'invoice_number' => 'GW-2026-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'service_connection_id' => $connection->id,
                'meter_reading_id' => $invoiceReading->id,
                'rate_schedule_id' => $schedule->id,
                'billing_period_start' => '2026-01-01',
                'billing_period_end' => '2026-01-31',
                'previous_balance' => 0,
                'base_amount' => 500.00,
                'penalty_amount' => 0,
                'total_amount' => 500.00,
                'due_date' => '2026-02-15',
                'status' => 'unpaid',
            ]);
        }

        $this->assertSame('GW-2026-00012', $service->generateInvoiceNumber());
    }

    public function test_run_window_is_the_calendar_month_of_period_end(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-01-29');

        $report = $service->run('2026-02-28');

        $this->assertSame('skipped', $report->firstWhere('account_number', $connection->account_number)['status']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_penalty_compounds_on_full_carried_total(): void
    {
        $service = new BillingService;
        $rule = $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 120.00, '2026-07-30');
        $oldReading = $this->reading($connection->id, 100.00, '2026-06-30');

        Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $oldReading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'previous_balance' => 0,
            'base_amount' => 1000.00,
            'penalty_amount' => 20.00,
            'total_amount' => 1020.00,
            'due_date' => '2026-06-01',
            'status' => 'unpaid',
        ]);

        $invoice = $service->billConnection($connection, $reading, $schedule, '2026-07-01', '2026-07-31', $rule);

        $this->assertSame(1020.00, (float) $invoice->previous_balance);
        $this->assertSame(20.40, (float) $invoice->penalty_amount);
        $this->assertSame(2240.40, (float) $invoice->total_amount);
    }

    public function test_billing_run_command_creates_invoices_and_succeeds(): void
    {
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $this->artisan('billing:run', ['--period' => '2026-07-31', '--sync' => true])->assertSuccessful();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(750.00, (float) Invoice::first()->total_amount);

        $run = BillingRun::first();
        $this->assertSame('completed', $run->status);
        $this->assertSame('billed', $run->report[0]['status']);
    }

    public function test_billing_run_command_dispatches_job_by_default(): void
    {
        Queue::fake();

        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $this->artisan('billing:run', ['--period' => '2026-07-31'])->assertSuccessful();

        Queue::assertPushed(RunBillingJob::class, fn (RunBillingJob $job) => $job->periodEnd === '2026-07-31');

        $this->assertSame(0, Invoice::count(), 'Nothing may be billed synchronously on the default path.');

        $run = BillingRun::first();
        $this->assertSame('running', $run->status);
        $this->assertSame('2026-07-31', $run->period_end->toDateString());
    }

    public function test_billing_run_command_refuses_a_second_running_run_for_the_same_period(): void
    {
        Queue::fake();

        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');
        BillingRun::create(['period_end' => '2026-07-31', 'status' => 'running']);

        $this->artisan('billing:run', ['--period' => '2026-07-31'])->assertExitCode(1);

        Queue::assertNotPushed(RunBillingJob::class);
        $this->assertSame(1, BillingRun::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_billing_report_command_prints_the_stored_report(): void
    {
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $this->artisan('billing:run', ['--period' => '2026-07-31', '--sync' => true])->assertSuccessful();
        $run = BillingRun::first();

        $this->artisan('billing:report', ['run' => $run->id])
            ->assertSuccessful()
            ->expectsOutputToContain($connection->account_number);

        $this->artisan('billing:report', ['run' => $run->id])
            ->assertSuccessful()
            ->expectsOutputToContain('750.00');
    }

    public function test_billing_report_command_reports_a_failed_run(): void
    {
        $run = BillingRun::create([
            'period_end' => '2026-07-31',
            'status' => 'failed',
            'error' => 'boom',
            'finished_at' => now(),
        ]);

        $this->artisan('billing:report', ['run' => $run->id])
            ->assertExitCode(1)
            ->expectsOutputToContain('boom');
    }

    public function test_run_rejects_invalid_calendar_period(): void
    {
        $service = new BillingService;

        $this->expectException(InvalidArgumentException::class);
        $service->run('2026-02-31');
    }

    public function test_billing_run_command_rejects_invalid_calendar_period(): void
    {
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $this->artisan('billing:run', ['--period' => '2026-02-31'])->assertExitCode(1);

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, BillingRun::count());
    }

    public function test_run_skips_unflagged_negative_usage(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, -50.00, '2026-07-30', 0);

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Non-positive usage', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_skips_zero_usage_reading(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 0.00, '2026-07-30', 0);

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Zero usage', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_skips_flat_schedule_without_rate(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = RateSchedule::create([
            'name' => 'Broken Flat Rate',
            'type' => 'flat',
            'flat_rate' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('misconfigured', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_run_skips_tiered_schedule_without_tiers(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = RateSchedule::factory()->tiered()->create(['effective_from' => '2026-01-01']);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('misconfigured', $row['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_penalty_returns_zero_for_unparseable_dates(): void
    {
        $service = new BillingService;
        $rule = $this->seedPenaltyRule();

        $this->assertSame(0.0, $service->computePenalty(1000.00, 'not-a-date', '2026-08-16', $rule));
        $this->assertSame(0.0, $service->computePenalty(1000.00, '2026-06-01', 'not-a-date', $rule));
    }

    public function test_run_notes_global_rate_fallback_when_assigned_schedule_expired(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $global = $this->flatSchedule(10.00);
        $expired = RateSchedule::create([
            'name' => 'Expired Rate',
            'type' => 'flat',
            'flat_rate' => 5.00,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-06-30',
        ]);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $expired->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('billed', $row['status']);
        $this->assertStringContainsString('Global rate', $row['reason']);
        $this->assertSame(750.00, $row['total_amount']);
    }

    public function test_run_reports_already_billed_before_zero_usage_guard(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $zeroReading = $this->reading($connection->id, 0.00, '2026-07-30', 0);

        Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $zeroReading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-07-01',
            'billing_period_end' => '2026-07-31',
            'previous_balance' => 0,
            'base_amount' => 0,
            'penalty_amount' => 0,
            'total_amount' => 0,
            'due_date' => '2026-08-15',
            'status' => 'unpaid',
        ]);

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Already billed', $row['reason']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_invoice_cannot_duplicate_a_reading_via_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $reading = $this->reading($connection->id, 75.00, '2026-07-30');

        $attributes = [
            'service_connection_id' => $connection->id,
            'meter_reading_id' => $reading->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-07-01',
            'billing_period_end' => '2026-07-31',
            'previous_balance' => 0,
            'base_amount' => 750.00,
            'penalty_amount' => 0,
            'total_amount' => 750.00,
            'due_date' => '2026-08-15',
            'status' => 'unpaid',
        ];

        Invoice::create(['invoice_number' => 'GW-2026-00001', ...$attributes]);
        Invoice::create(['invoice_number' => 'GW-2026-00002', ...$attributes]);
    }

    public function test_run_includes_reading_entered_late_on_period_end_day(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-31 23:59:59');

        $report = $service->run('2026-07-31');
        $row = $report->firstWhere('account_number', $connection->account_number);

        $this->assertSame('billed', $row['status']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_run_rolls_back_everything_on_mid_run_failure(): void
    {
        $service = new BillingService;
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);

        $first = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $second = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($first->id, 75.00, '2026-07-30');
        $this->reading($second->id, 60.00, '2026-07-29');

        $old = Invoice::create([
            'invoice_number' => 'GW-2026-00001',
            'service_connection_id' => $second->id,
            'meter_reading_id' => $this->reading($second->id, 40.00, '2026-06-30')->id,
            'rate_schedule_id' => $schedule->id,
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            'previous_balance' => 0,
            'base_amount' => 400.00,
            'penalty_amount' => 0,
            'total_amount' => 400.00,
            'due_date' => '2026-07-01',
            'status' => 'unpaid',
        ]);

        $attempts = 0;
        Invoice::creating(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 2) {
                throw new RuntimeException('mid-run failure');
            }
        });

        try {
            $service->run('2026-07-31');
            $this->fail('Expected the run to throw on the second invoice.');
        } catch (RuntimeException $exception) {
            $this->assertSame('mid-run failure', $exception->getMessage());
        }

        $this->assertSame(0, Invoice::whereKeyNot($old->id)->count(), 'No invoice from the failed run may persist.');
        $this->assertSame('unpaid', $old->fresh()->status, 'The overdue pass must roll back too.');
    }
}
