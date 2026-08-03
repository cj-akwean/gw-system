<?php

namespace Tests\Feature;

use App\Jobs\RunBillingJob;
use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\PenaltyRule;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunBillingJobTest extends TestCase
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

    private function reading(int $connectionId, float $used, string $date): MeterReading
    {
        return MeterReading::factory()->create([
            'service_connection_id' => $connectionId,
            'present_reading' => 120.00 + $used,
            'previous_reading' => 120.00,
            'cu_m_used' => $used,
            'entered_at' => $date,
            'flagged' => 0,
        ]);
    }

    public function test_job_bills_connections_and_records_completed_report(): void
    {
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');
        $run = BillingRun::create(['period_end' => '2026-07-31', 'status' => 'running']);

        RunBillingJob::dispatch('2026-07-31', $run->id);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(750.00, (float) Invoice::first()->total_amount);

        $completed = $run->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame('billed', $completed->report[0]['status']);
        $this->assertSame($connection->account_number, $completed->report[0]['account_number']);
        $this->assertSame(750.00, (float) $completed->report[0]['total_amount']);
    }

    public function test_job_marks_run_failed_and_rethrows_on_exception(): void
    {
        $run = BillingRun::create(['period_end' => '2026-07-31', 'status' => 'running']);

        $this->mock(BillingService::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('boom'));
        });

        $job = new RunBillingJob('2026-07-31', $run->id);

        try {
            $job->handle(app(BillingService::class));
            $this->fail('Expected the job to rethrow the billing failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $failed = $run->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('boom', $failed->error);
        $this->assertNotNull($failed->finished_at);
        $this->assertSame(0, Invoice::count());
    }

    public function test_job_retry_clears_failed_status_before_rerunning(): void
    {
        $this->seedPenaltyRule();
        $schedule = $this->flatSchedule(10.00);
        $connection = ServiceConnection::factory()->create(['rate_schedule_id' => $schedule->id]);
        $this->reading($connection->id, 75.00, '2026-07-30');
        $run = BillingRun::create([
            'period_end' => '2026-07-31',
            'status' => 'failed',
            'error' => 'previous failure',
            'finished_at' => now(),
        ]);

        $job = new RunBillingJob('2026-07-31', $run->id);
        $job->handle(app(BillingService::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($run->fresh()->error);
        $this->assertSame(1, Invoice::count());
    }
}
