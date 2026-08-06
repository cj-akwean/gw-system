<?php

namespace App\Services;

use App\Jobs\RunBillingJob;
use App\Models\BillingRun;
use Illuminate\Database\UniqueConstraintViolationException;

class BillingRunService
{
    /**
     * Start a billing run: create the audit row (guarding against concurrent
     * runs for the same period) and dispatch the queued job.
     *
     * @return array{run: ?BillingRun, error: ?string}
     */
    public function start(?string $periodEnd = null, bool $force = false, ?int $startedByUserId = null): array
    {
        $periodEnd ??= date('Y-m-d', strtotime('last day of previous month'));

        $inProgress = BillingRun::where('period_end', $periodEnd)
            ->where('status', 'running')
            ->first();

        if ($inProgress) {
            if (! $force || ! $inProgress->isStale()) {
                return [
                    'run' => null,
                    'error' => "A billing run (#{$inProgress->id}) for {$periodEnd} is already in progress.",
                ];
            }

            $inProgress->forceFill([
                'status' => 'failed',
                'error' => 'Abandoned run — forced failed by '.($startedByUserId !== null ? "admin #{$startedByUserId}" : 'an operator').' on '.now()->toDateTimeString().'.',
                'finished_at' => now(),
            ])->save();
        }

        try {
            $run = BillingRun::create([
                'period_end' => $periodEnd,
                'status' => 'running',
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $racer = BillingRun::where('period_end', $periodEnd)
                ->where('status', 'running')
                ->first();

            return [
                'run' => null,
                'error' => 'A concurrent billing run ('.($racer?->id ? "#{$racer->id} " : '')."for {$periodEnd}) is already in progress.",
            ];
        }

        RunBillingJob::dispatch($periodEnd, $run->id);

        return ['run' => $run, 'error' => null];
    }
}
