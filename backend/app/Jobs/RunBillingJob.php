<?php

namespace App\Jobs;

use App\Models\BillingRun;
use App\Services\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RunBillingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        public ?string $periodEnd = null,
        public int $billingRunId = 0,
    ) {}

    public function handle(BillingService $billing): void
    {
        $run = BillingRun::find($this->billingRunId);

        if (! $run) {
            throw new RuntimeException("billing run #{$this->billingRunId} not found — refusing to bill without an audit row.");
        }

        // The run row is the source of truth, not whatever the caller passed.
        $periodEnd = $run->period_end->toDateString();

        if ($this->periodEnd !== null && $this->periodEnd !== $periodEnd) {
            $run->forceFill([
                'status' => 'failed',
                'error' => "Mismatched period: dispatched for {$this->periodEnd} but run #{$run->id} is for {$periodEnd} — refusing to bill the wrong month.",
                'finished_at' => now(),
            ])->save();

            return;
        }

        // Never resurrect a run that an operator force-failed to abandon.
        if ($run->status === 'failed' && filled($run->error) && str_contains($run->error, 'forced failed')) {
            Log::info("billing run #{$run->id} was force-failed by an operator — not resuming.");

            return;
        }

        // Is this run trying to become the single active run for the period?
        if (BillingRun::where('period_end', $periodEnd)->where('status', 'running')->where('id', '!=', $run->id)->exists()) {
            $run->forceFill([
                'status' => 'failed',
                'error' => "Superseded: another billing run for {$periodEnd} is in progress (run #{$run->id} not resumed).",
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $run->forceFill([
                'status' => 'running',
                'error' => null,
                'finished_at' => null,
            ])->save();
        } catch (UniqueConstraintViolationException $exception) {
            // Genuine UPDATE race — the probe raced with an identical reset. Record and stop.
            $run->forceFill([
                'status' => 'failed',
                'error' => "Superseded: another billing run for {$periodEnd} is in progress (run #{$run->id} not resumed).",
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $report = $billing->run($periodEnd);

            $run->forceFill([
                'status' => 'completed',
                'report' => $report->all(),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }
}
