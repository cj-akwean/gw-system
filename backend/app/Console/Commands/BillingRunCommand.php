<?php

namespace App\Console\Commands;

use App\Jobs\RunBillingJob;
use App\Models\BillingRun;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

class BillingRunCommand extends Command
{
    protected $signature = 'billing:run {--period= : Billing period end date (Y-m-d). Defaults to the end of last month.} {--sync : Run synchronously instead of dispatching a queued job.} {--force : Mark a stale running run failed and start a fresh one.}';

    protected $description = 'Run the monthly billing cycle for all active connections';

    public function handle(BillingService $billing): int
    {
        $period = $this->option('period');

        if ($period && ! $this->isValidPeriod((string) $period)) {
            $this->error('Invalid --period. Use a real calendar date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $periodEnd = $period ? (string) $period : date('Y-m-d', strtotime('last day of previous month'));

        $inProgress = BillingRun::where('period_end', $periodEnd)
            ->where('status', 'running')
            ->first();

        if ($inProgress) {
            if (! $this->option('force') || ! $inProgress->isStale()) {
                $this->error("A billing run (#{$inProgress->id}) for {$periodEnd} is already in progress.");
                $this->error("Inspect it with: php artisan billing:report {$inProgress->id}");

                return self::FAILURE;
            }

            $inProgress->forceFill([
                'status' => 'failed',
                'error' => 'Abandoned run — forced failed by billing:run --force on '.now()->toDateTimeString().'.',
                'finished_at' => now(),
            ])->save();

            $this->info("Marked stale billing run #{$inProgress->id} for {$periodEnd} as failed and started a fresh run.");
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

            $this->error('A concurrent billing run ('.($racer?->id ? "#{$racer->id} " : '')."for {$periodEnd}) is already in progress.");
            $this->error('Inspect it with: php artisan billing:report '.($racer?->id ?? '?').'.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            try {
                $report = $billing->run($periodEnd);
            } catch (Throwable $exception) {
                $run->forceFill([
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                    'finished_at' => now(),
                ])->save();

                throw $exception;
            }

            $run->forceFill([
                'status' => 'completed',
                'report' => $report->all(),
                'finished_at' => now(),
            ])->save();

            $this->printReport($report);

            $billed = $report->where('status', 'billed')->count();
            $skipped = $report->count() - $billed;

            $this->info("Billing run complete: {$billed} invoice(s) created, {$skipped} connection(s) skipped.");

            return self::SUCCESS;
        }

        RunBillingJob::dispatch($periodEnd, $run->id);

        $this->info("Billing run #{$run->id} for {$periodEnd} dispatched to the queue.");
        $this->info("Check its result with: php artisan billing:report {$run->id}");

        return self::SUCCESS;
    }

    private function printReport(Collection $report): void
    {
        $this->table(
            ['Account', 'Status', 'Reason', 'Invoice', 'Total (PHP)'],
            $report->map(fn (array $row) => [
                $row['account_number'],
                $row['status'],
                $row['reason'] ?? '',
                $row['invoice_number'] ?? '',
                $row['total_amount'] !== null ? number_format($row['total_amount'], 2) : '',
            ])->all(),
        );
    }

    private function isValidPeriod(string $period): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $period, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
