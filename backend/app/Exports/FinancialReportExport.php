<?php

namespace App\Exports;

use App\Services\FinancialReportService;

class FinancialReportExport implements \Maatwebsite\Excel\Concerns\WithMultipleSheets
{
    public function __construct(
        protected FinancialReportService $service,
        protected ?string $from = null,
        protected ?string $to = null,
    ) {}

    public function sheets(): array
    {
        $data = $this->service->build($this->from, $this->to);

        return [
            new FinancialReportSummarySheet($data),
            new FinancialReportAgingSheet($data['aging']),
            new FinancialReportIncomeSheet($data['income']),
            new FinancialReportLedgerSheet($this->from, $this->to),
        ];
    }
}
