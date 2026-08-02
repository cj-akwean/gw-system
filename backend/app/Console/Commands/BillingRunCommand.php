<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class BillingRunCommand extends Command
{
    protected $signature = 'billing:run {--period= : Billing period end date (Y-m-d). Defaults to the end of last month.}';

    protected $description = 'Run the monthly billing cycle for all active connections';

    public function handle(BillingService $billing): int
    {
        $period = $this->option('period');

        if ($period && ! $this->isValidPeriod((string) $period)) {
            $this->error('Invalid --period. Use a real calendar date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $report = $billing->run($period ? (string) $period : null);

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

        $billed = $report->where('status', 'billed')->count();
        $skipped = $report->count() - $billed;

        $this->info("Billing run complete: {$billed} invoice(s) created, {$skipped} connection(s) skipped.");

        return self::SUCCESS;
    }

    private function isValidPeriod(string $period): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $period, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }
}
