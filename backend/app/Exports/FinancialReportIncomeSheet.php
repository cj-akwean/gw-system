<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportIncomeSheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array{
     *     gross_billed: float,
     *     cash_collections: float,
     *     misc_income: float,
     *     reconnection_fees: float,
     *     setup_fees: float,
     *     net_operating_income: float,
     * }  $income
     */
    public function __construct(protected array $income) {}

    public function title(): string
    {
        return 'Income Statement';
    }

    public function headings(): array
    {
        return ['Item', 'Amount (PHP)'];
    }

    public function array(): array
    {
        return [
            ['Gross billed revenue', number_format((float) $this->income['gross_billed'], 2, '.', '')],
            ['Actual cash collections', number_format((float) $this->income['cash_collections'], 2, '.', '')],
            ['Miscellaneous income (penalty charges)', number_format((float) $this->income['misc_income'], 2, '.', '')],
            ['Reconnection fees (not tracked)', number_format((float) $this->income['reconnection_fees'], 2, '.', '')],
            ['New connection setup fees (not tracked)', number_format((float) $this->income['setup_fees'], 2, '.', '')],
            ['Net operating income (gross billed + misc - collections)', number_format((float) $this->income['net_operating_income'], 2, '.', '')],
        ];
    }
}
