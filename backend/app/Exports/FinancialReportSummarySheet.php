<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportSummarySheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array<string, int|float>  $summary
     */
    public function __construct(
        protected array $summary,
        protected string $generatedAt,
    ) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function headings(): array
    {
        return ['Metric', 'Value'];
    }

    public function array(): array
    {
        return [
            ['Generated', $this->generatedAt],
            ['Active customers', $this->summary['active_connections']],
            ['Unpaid bills', $this->summary['unpaid_bills']],
            ['Overdue bills', $this->summary['overdue_bills']],
            ['Outstanding amount (PHP)', number_format((float) $this->summary['outstanding_amount'], 2, '.', '')],
            ['Revenue this month (PHP)', number_format((float) $this->summary['revenue_this_month'], 2, '.', '')],
        ];
    }
}
