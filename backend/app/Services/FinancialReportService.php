<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the financial report dataset shared by the Filament "Financial
 * Report" page, the Excel export and the PDF export, so the numbers can
 * never drift between the three renderings.
 */
class FinancialReportService
{
    public function __construct(
        protected DashboardMetricsService $metrics,
    ) {}

    /**
     * @return array{
     *     generatedAt: string,
     *     months: int,
     *     summary: array<string, int|float>,
     *     monthlyRevenue: array<string, float>,
     * }
     */
    public function build(int $months = 12): array
    {
        $months = max(1, $months);

        return [
            'generatedAt' => now()->format('M d, Y h:i A'),
            'months' => $months,
            'summary' => [
                'active_connections' => $this->metrics->activeConnectionsCount(),
                'unpaid_bills' => $this->metrics->unpaidInvoicesCount(),
                'overdue_bills' => $this->metrics->overdueInvoicesCount(),
                'outstanding_amount' => $this->metrics->receivablesOutstanding(),
                'revenue_this_month' => $this->metrics->revenueThisMonth(),
            ],
            'monthlyRevenue' => $this->metrics->revenueLastMonths($months),
        ];
    }

    /**
     * Revenue-by-month as display rows (oldest first), ready for tables.
     *
     * @param  array<string, float>  $byMonth  keyed "Y-m"
     * @return Collection<int, array{label: string, revenue: float}>
     */
    public function monthlyRows(array $byMonth): Collection
    {
        return collect($byMonth)
            ->map(fn (float $revenue, string $key): array => [
                'label' => CarbonImmutable::createFromFormat('Y-m', $key)->format('M Y'),
                'revenue' => $revenue,
            ])
            ->values();
    }
}
