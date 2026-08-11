<?php

namespace App\Jobs;

use App\Models\BillingRun;
use App\Services\BillingService;
use App\Support\AdminNotifier;
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

            AdminNotifier::notify(
                'Billing run blocked',
                'Run #'.$run->id.' for '.$periodEnd.' was refused: dispatched for the wrong period ('.$this->periodEnd.').',
                'warning',
                'View run',
                $this->runPath($run),
            );

            return;
        }

        // Never resurrect a run that an operator force-failed to abandon.
        if ($run->status === 'failed' && filled($run->error) && str_contains($run->error, 'forced failed')) {
            Log::info("billing run #{$run->id} was force-failed by an operator — not resuming.");

            AdminNotifier::notify(
                'Billing run skipped',
                'Run #'.$run->id.' for '.$periodEnd.' was force-failed by an operator — not resuming.',
                'warning',
                'View run',
                $this->runPath($run),
            );

            return;
        }

        // Is this run trying to become the single active run for the period?
        if (BillingRun::where('period_end', $periodEnd)->where('status', 'running')->where('id', '!=', $run->id)->exists()) {
            $run->forceFill([
                'status' => 'failed',
                'error' => "Superseded: another billing run for {$periodEnd} is in progress (run #{$run->id} not resumed).",
                'finished_at' => now(),
            ])->save();

            AdminNotifier::notify(
                'Billing run superseded',
                'Run #'.$run->id.' for '.$periodEnd.' was superseded — another run for the same period is in progress.',
                'warning',
                'View run',
                $this->runPath($run),
            );

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

            AdminNotifier::notify(
                'Billing run superseded',
                'Run #'.$run->id.' for '.$periodEnd.' was superseded — another run for the same period is in progress.',
                'warning',
                'View run',
                $this->runPath($run),
            );

            return;
        }

        try {
            $report = $billing->run($periodEnd);

            $run->forceFill([
                'status' => 'completed',
                'report' => $report->all(),
                'finished_at' => now(),
            ])->save();

            AdminNotifier::notify(
                'Billing run completed',
                'Run #'.$run->id.' for '.$periodEnd.' — '.$report->count().' invoice(s), ₱'
                    .number_format((float) $report->sum('total_amount'), 2).' total.',
                'success',
                'View run',
                $this->runPath($run),
            );
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            AdminNotifier::notify(
                'Billing run failed',
                'Run #'.$run->id.' for '.$periodEnd.' failed: '.$exception->getMessage(),
                'danger',
                'View run',
                $this->runPath($run),
            );

            throw $exception;
        }
    }

    /**
     * The run's view route as a host-independent path suffix, so stored
     * notification rows resolve on any host (same convention as the resend
     * notifications).
     */
    private function runPath(BillingRun $run): string
    {
        return (string) parse_url(route('filament.admin.resources.billing-runs.view', $run), PHP_URL_PATH);
    }
}
