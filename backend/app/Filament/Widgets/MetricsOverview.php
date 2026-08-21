<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ServiceConnectionResource;
use App\Services\DashboardMetricsService;
use App\Services\FinancialReportService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MetricsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $metrics = app(DashboardMetricsService::class);

        $revenueDelta = $metrics->revenueDelta();
        $unpaidDelta = $metrics->unpaidDelta();
        $collectionRate = $metrics->collectionRateForMonth();

        $stats = [
            Stat::make('Active customers', $metrics->activeConnectionsCount())
                ->description('service connections')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->url($this->filteredListUrl(
                    ServiceConnectionResource::getUrl('index'),
                    ['status' => ['value' => 'active']],
                )),
            Stat::make('Unpaid bills', $metrics->unpaidInvoicesCount())
                ->description($this->deltaDescription($unpaidDelta, 'vs last month'))
                ->descriptionIcon($this->deltaIcon($unpaidDelta))
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['values' => ['unpaid']]],
                )),
            Stat::make('Overdue bills', $metrics->overdueInvoicesCount())
                ->description('2% penalty per month')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['values' => ['overdue']]],
                )),
            Stat::make('Outstanding amount', $this->peso($metrics->receivablesOutstanding()))
                ->description('unpaid + overdue')
                ->icon('heroicon-o-banknotes')
                ->color('danger')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['values' => ['unpaid', 'overdue']]],
                )),
            Stat::make('Revenue this month', $this->peso($metrics->revenueThisMonth()))
                ->description($this->deltaDescription($revenueDelta, 'vs last month'))
                ->descriptionIcon($this->deltaIcon($revenueDelta))
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success')
                ->url($this->filteredListUrl(
                    PaymentResource::getUrl('index'),
                    [
                        'paid_at' => [
                            'paid_from' => now()->startOfMonth()->toDateString(),
                            'paid_until' => now()->endOfMonth()->toDateString(),
                        ],
                    ],
                )),
        ];

        if ($collectionRate !== null) {
            $stats[] = Stat::make('Collection rate', $collectionRate.'%')
                ->description('collections ÷ billed this month')
                ->descriptionIcon('heroicon-o-percent-badge')
                ->icon('heroicon-o-scale')
                ->color('info')
                ->url($this->filteredListUrl(
                    PaymentResource::getUrl('index'),
                    [
                        'paid_at' => [
                            'paid_from' => now()->startOfMonth()->toDateString(),
                            'paid_until' => now()->endOfMonth()->toDateString(),
                        ],
                    ],
                ));
        }

        // AR aging: the amount of open receivables 90+ days overdue — the
        // cohort most likely to require write-off or follow-up action.
        $aging = app(FinancialReportService::class)->agingBuckets();
        $overdue90 = $aging->firstWhere('key', 'overdue90');
        if ($overdue90 !== null && (float) $overdue90['amount'] > 0) {
            $stats[] = Stat::make('Receivables 90+ days', $this->peso((float) $overdue90['amount']))
                ->description($overdue90['count'].' invoices aged past 90 days')
                ->icon('heroicon-o-clock')
                ->color('danger')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['values' => ['unpaid', 'overdue']]],
                ));
        }

        return $stats;
    }

    private function deltaDescription(float $delta, string $suffix): string
    {
        if ($delta === 0.0) {
            return 'No change '.$suffix;
        }

        $direction = $delta > 0 ? 'up' : 'down';

        return abs($delta).'% '.$direction.' '.$suffix;
    }

    private function deltaIcon(float $delta): string
    {
        if ($delta === 0.0) {
            return 'heroicon-o-minus';
        }

        return $delta > 0
            ? 'heroicon-o-arrow-trending-up'
            : 'heroicon-o-arrow-trending-down';
    }

    private function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    /**
     * Filament list pages expose their table filters as the URL query-string
     * parameter `filters` (Livewire #[Url] on the tableFilters property), whose
     * value is a JSON-encoded array — exactly what the browser address bar
     * holds after a user applies filters in the UI.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filteredListUrl(string $url, array $filters): string
    {
        return $url.'?filters='.rawurlencode((string) json_encode($filters));
    }
}
