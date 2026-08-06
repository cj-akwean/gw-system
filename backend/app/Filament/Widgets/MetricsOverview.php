<?php

namespace App\Filament\Widgets;

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
                ->color('info'),
            Stat::make('Unpaid bills', $metrics->unpaidInvoicesCount())
                ->description('within grace period')
                ->icon('heroicon-o-document-text')
                ->color('warning'),
            Stat::make('Overdue bills', $metrics->overdueInvoicesCount())
                ->description('2% penalty per month')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
            Stat::make('Outstanding amount', $this->peso($metrics->receivablesOutstanding()))
                ->description('unpaid + overdue')
                ->icon('heroicon-o-banknotes')
                ->color('danger'),
            Stat::make('Revenue this month', $this->peso($metrics->revenueThisMonth()))
                ->description('collections so far')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success'),
        ];
    }

    private function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }
}
