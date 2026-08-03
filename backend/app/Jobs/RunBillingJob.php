<?php

namespace App\Jobs;

use App\Models\BillingRun;
use App\Services\BillingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBillingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public ?string $periodEnd = null,
        public int $billingRunId = 0,
    ) {}

    public function handle(BillingService $billing): void
    {
        $run = BillingRun::findOrFail($this->billingRunId);

        $run->forceFill([
            'status' => 'running',
            'error' => null,
            'finished_at' => null,
        ])->save();

        try {
            $report = $billing->run($this->periodEnd);

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
