<?php

namespace App\Exports;

use App\Services\FinancialReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportRevenueSheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(protected array $monthlyRevenue) {}

    public function title(): string
    {
        return 'Revenue by Month';
    }

    public function headings(): array
    {
        return ['Month', 'Revenue (PHP)'];
    }

    public function array(): array
    {
        return app(FinancialReportService::class)
            ->monthlyRows($this->monthlyRevenue)
            ->map(fn (array $row): array => [
                $row['label'],
                number_format($row['revenue'], 2, '.', ''),
            ])
            ->all();
    }
}
