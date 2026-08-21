<?php

namespace App\Services;

use App\Models\BillingRun;
use App\Models\Invoice;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
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

    /**
     * Collection rate for the current calendar month: payments recorded divided
     * by what was billed (invoices whose period ends in the month). Null when
     * nothing was billed that month — a 0% would mislead an operator into
     * thinking collections collapsed.
     */
    public function collectionRateForMonth(): ?float
    {
        $billed = (float) Invoice::query()
            ->where('billing_period_end', '>=', now()->startOfMonth())
            ->where('billing_period_end', '<=', now()->endOfMonth())
            ->sum('total_amount');

        if ($billed <= 0) {
            return null;
        }

        return round(($this->revenueThisMonth() / $billed) * 100, 1);
    }

    /**
     * Signed percentage change of this month's revenue vs last month's.
     * Returns 0 when last month had no revenue (no meaningful delta), so the
     * dashboard never shows a bogus "∞% up".
     */
    public function revenueDelta(): float
    {
        $last = $this->revenueBetween(
            now()->startOfMonth()->subMonth(),
            now()->endOfMonth()->subMonth(),
        );

        if ($last <= 0) {
            return 0.0;
        }

        return round((($this->revenueThisMonth() - $last) / $last) * 100, 1);
    }

    /**
     * Signed percentage change of the open (unpaid + overdue) bill count for
     * the current billing month vs the previous billing month. Negative =
     * fewer open bills (good).
     */
    public function unpaidDelta(): float
    {
        $openInMonth = function (CarbonInterface $month): int {
            return Invoice::query()
                ->whereIn('status', ['unpaid', 'overdue'])
                ->where('billing_period_end', '>=', $month->copy()->startOfMonth())
                ->where('billing_period_end', '<=', $month->copy()->endOfMonth())
                ->count();
        };

        $last = $openInMonth(now()->startOfMonth()->subMonth());

        if ($last <= 0) {
            return 0.0;
        }

        $current = $openInMonth(now()->startOfMonth());

        return round((($current - $last) / $last) * 100, 1);
    }

    /**
     * The "needs your attention" surface for the dashboard widget. Each item
     * carries what the widget needs to render a clickable, scannable row
     * without querying per category. Unread admin notifications ride along as
     * a header count (the full list lives on the Notification Hub).
     *
     * @return array{
     *     overdue: Collection<int, array{invoice_id: int, invoice_number: string, account_number: string, registered_name: string, amount: float, due_date: string|null}>,
     *     pending_connections: Collection<int, array{connection_id: int, account_number: string, registered_name: string}>,
     *     low_stock: Collection<int, array{item_id: int, name: string, status: string, quantity_on_hand: float}>,
     *     billing_runs: Collection<int, array{run_id: int, period_end: string|null, status: string, is_stale: bool}>,
     *     unread_count: int,
     * }
     */
    public function needsAttention(): array
    {
        $overdue = Invoice::query()
            ->with(['serviceConnection'])
            ->where('status', 'overdue')
            ->orderByDesc('total_amount')
            ->limit(5)
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'account_number' => $invoice->serviceConnection?->account_number ?? '—',
                'registered_name' => $invoice->serviceConnection?->registered_name ?? '—',
                'amount' => (float) $invoice->total_amount,
                'due_date' => $invoice->due_date?->toDateString(),
            ]);

        $pending = ServiceConnection::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(5)
            ->get()
            ->map(fn (ServiceConnection $connection): array => [
                'connection_id' => $connection->id,
                'account_number' => $connection->account_number,
                'registered_name' => $connection->registered_name,
            ]);

        $lowStock = InventoryItem::query()
            ->get(['id', 'name', 'quantity_on_hand', 'reorder_level'])
            ->filter(fn (InventoryItem $item): bool => $item->status() !== 'ok')
            ->sortByDesc(fn (InventoryItem $item): bool => $item->status() === 'no_stock')
            ->take(5)
            ->values()
            ->map(fn (InventoryItem $item): array => [
                'item_id' => $item->id,
                'name' => $item->name,
                'status' => $item->status(),
                'quantity_on_hand' => (float) $item->quantity_on_hand,
            ]);

        $runs = BillingRun::query()
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->filter(fn (BillingRun $run): bool => $run->status === 'failed' || ($run->status === 'running' && $run->isStale()))
            ->values()
            ->map(fn (BillingRun $run): array => [
                'run_id' => $run->id,
                'period_end' => $run->period_end?->toDateString(),
                'status' => $run->status,
                'is_stale' => $run->status === 'running' && $run->isStale(),
            ]);

        return [
            'overdue' => $overdue,
            'pending_connections' => $pending,
            'low_stock' => $lowStock,
            'billing_runs' => $runs,
            'unread_count' => $this->unreadNotificationCount(),
        ];
    }

    /**
     * Unread Filament-format notifications for the current admin, matching the
     * Notification Hub's counting query. Returns 0 outside an admin request.
     */
    public function unreadNotificationCount(): int
    {
        $user = auth('admin')->user();

        if (! $user instanceof User) {
            return 0;
        }

        return DatabaseNotification::query()
            ->where('notifiable_id', $user->getKey())
            ->where('notifiable_type', $user->getMorphClass())
            ->where('data->format', 'filament')
            ->unread()
            ->count();
    }
}
