<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportAgingSheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  Collection<int, array{key: string, label: string, range_label: string, count: int, amount: float, penalty: float}>  $aging
     */
    public function __construct(protected Collection $aging) {}

    public function title(): string
    {
        return 'AR Aging';
    }

    public function headings(): array
    {
        return ['Aging bucket', 'Invoice count', 'Outstanding balance (PHP)', 'Penalties accrued (PHP)'];
    }

    public function array(): array
    {
        return $this->aging
            ->map(fn (array $bucket): array => [
                $bucket['label'],
                $bucket['count'],
                number_format((float) $bucket['amount'], 2, '.', ''),
                number_format((float) $bucket['penalty'], 2, '.', ''),
            ])
            ->all();
    }
}
