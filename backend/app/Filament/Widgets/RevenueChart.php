<?php

namespace App\Filament\Widgets;

use App\Services\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Filament\Widgets\LineChartWidget;

class RevenueChart extends LineChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Revenue — last 6 months';

    protected function getData(): array
    {
        $byMonth = app(DashboardMetricsService::class)->revenueLastMonths(6);

        return [
            'datasets' => [
                [
                    'label' => 'Revenue',
                    'data' => array_values($byMonth),
                ],
            ],
            'labels' => array_map(
                fn (string $key): string => CarbonImmutable::createFromFormat('Y-m', $key)->format('M'),
                array_keys($byMonth),
            ),
        ];
    }
}
