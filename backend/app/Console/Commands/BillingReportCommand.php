<?php

namespace App\Console\Commands;

use App\Models\BillingRun;
use Illuminate\Console\Command;

class BillingReportCommand extends Command
{
    protected $signature = 'billing:report {run : The billing run ID}';

    protected $description = 'Show the report of a billing run';

    public function handle(): int
    {
        $run = BillingRun::find($this->argument('run'));

        if (! $run) {
            $this->error('Billing run not found.');

            return self::FAILURE;
        }

        if ($run->status === 'running') {
            $this->info("Billing run #{$run->id} for {$run->period_end->toDateString()} is still in progress.");

            return self::SUCCESS;
        }

        if ($run->status === 'failed') {
            $this->error("Billing run #{$run->id} for {$run->period_end->toDateString()} failed: {$run->error}");

            return self::FAILURE;
        }

        $this->table(
            ['Account', 'Status', 'Reason', 'Invoice', 'Total (PHP)'],
            collect($run->report ?? [])->map(fn (array $row) => [
                $row['account_number'],
                $row['status'],
                $row['reason'] ?? '',
                $row['invoice_number'] ?? '',
                $row['total_amount'] !== null ? number_format($row['total_amount'], 2) : '',
            ])->all(),
        );

        $billed = collect($run->report ?? [])->where('status', 'billed')->count();
        $skipped = count($run->report ?? []) - $billed;

        $this->info("Billing run #{$run->id} completed: {$billed} invoice(s) created, {$skipped} connection(s) skipped.");

        return self::SUCCESS;
    }
}
