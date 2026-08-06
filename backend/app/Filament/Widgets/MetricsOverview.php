<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\ServiceConnectionResource;
use App\Services\DashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MetricsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $metrics = app(DashboardMetricsService::class);

        return [
            Stat::make('Active customers', $metrics->activeConnectionsCount())
                ->description('service connections')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->url($this->filteredListUrl(
                    ServiceConnectionResource::getUrl('index'),
                    ['status' => ['value' => 'active']],
                )),
            Stat::make('Unpaid bills', $metrics->unpaidInvoicesCount())
                ->description('within grace period')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['value' => ['unpaid']]],
                )),
            Stat::make('Overdue bills', $metrics->overdueInvoicesCount())
                ->description('2% penalty per month')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['value' => ['overdue']]],
                )),
            Stat::make('Outstanding amount', $this->peso($metrics->receivablesOutstanding()))
                ->description('unpaid + overdue')
                ->icon('heroicon-o-banknotes')
                ->color('danger')
                ->url($this->filteredListUrl(
                    InvoiceResource::getUrl('index'),
                    ['status' => ['value' => ['unpaid', 'overdue']]],
                )),
            Stat::make('Revenue this month', $this->peso($metrics->revenueThisMonth()))
                ->description('collections so far')
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
