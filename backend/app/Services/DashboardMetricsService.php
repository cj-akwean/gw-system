<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class DashboardMetricsService
{
    public function activeConnectionsCount(): int
    {
        return ServiceConnection::query()->where('status', 'active')->count();
    }

    public function unpaidInvoicesCount(): int
    {
        return Invoice::query()->where('status', 'unpaid')->count();
    }

    public function overdueInvoicesCount(): int
    {
        return Invoice::query()->where('status', 'overdue')->count();
    }

    public function receivablesOutstanding(): float
    {
        return (float) Invoice::query()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->sum('total_amount');
    }

    public function revenueThisMonth(): float
    {
        return $this->revenueBetween(now()->startOfMonth(), now()->endOfMonth());
    }

    /**
     * Revenue per calendar month for the current month plus the $months-1
     * preceding ones, newest last. Zero-filled so the chart never has gaps.
     * Bounded to the end of the current month so future-dated payments can
     * never inflate revenue; same-month rows slightly ahead of the clock are
     * still counted, keeping this consistent with revenueThisMonth().
     *
     * @return array<string, float> keyed "Y-m"
     */
    public function revenueLastMonths(int $months = 6): array
    {
        $months = max(1, $months);

        $start = CarbonImmutable::instance(now()->startOfMonth())->subMonths($months - 1);

        $byMonth = Payment::query()
            ->where('paid_at', '>=', $start)
            ->where('paid_at', '<=', now()->endOfMonth())
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (Payment $payment): string => CarbonImmutable::instance($payment->paid_at)->format('Y-m'))
            ->map(fn (Collection $payments): float => (float) $payments->sum('amount'));

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $series[$month->format('Y-m')] = (float) ($byMonth[$month->format('Y-m')] ?? 0);
        }

        return $series;
    }

    private function revenueBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) Payment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }
}
