<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportSummarySheet implements FromArray, WithHeadings, WithTitle
{
    /**
     * @param  array{
     *     generatedAt: string,
     *     range: array{from: string, to: string, label: string},
     *     summary: array{total_receivables: float, total_collections: float},
     *     aging: \Illuminate\Support\Collection,
     *     income: array<string, float>,
     * }  $data
     */
    public function __construct(protected array $data) {}

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
            ['Generated', $this->data['generatedAt']],
            ['Period', $this->data['range']['label']],
            ['Total receivables (PHP)', number_format((float) $this->data['summary']['total_receivables'], 2, '.', '')],
            ['Total collections (PHP)', number_format((float) $this->data['summary']['total_collections'], 2, '.', '')],
        ];
    }
}
