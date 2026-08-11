<?php

namespace App\Exports;

use App\Services\FinancialReportService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FinancialReportExport implements WithMultipleSheets
{
    public function __construct(protected FinancialReportService $service) {}

    public function sheets(): array
    {
        $data = $this->service->build();

        return [
            new FinancialReportSummarySheet($data['summary'], $data['generatedAt']),
            new FinancialReportRevenueSheet($data['monthlyRevenue']),
        ];
    }
}
