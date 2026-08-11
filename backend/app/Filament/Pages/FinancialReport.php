<?php

namespace App\Filament\Pages;

use App\Exports\FinancialReportExport;
use App\Services\FinancialReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Financial report: dashboard metrics + revenue-by-month, with Excel and
 * PDF exports. The numbers are built by FinancialReportService so the page,
 * the spreadsheet and the PDF always show the same data.
 */
class FinancialReport extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Financial Report';

    protected static ?string $title = 'Financial Report';

    protected static ?string $slug = 'financial-report';

    protected string $view = 'filament.pages.financial-report';

    public function reportData(): array
    {
        return app(FinancialReportService::class)->build();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => Excel::download(
                    new FinancialReportExport(app(FinancialReportService::class)),
                    'financial-report-'.now()->format('Y-m-d-His').'.xlsx',
                )),
            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->action(fn () => $this->downloadPdf()),
        ];
    }

    protected function downloadPdf(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $this->reportData();

        $pdf = Pdf::loadHTML(view('pdfs.financial-report', $data)->render())
            ->setPaper('a4', 'portrait')
            ->setOption('enable_font_subsetting', true);

        // Filament actions only stream BinaryFileResponse downloads (Excel
        // works the same way); a plain dompdf Response cannot be serialized
        // through Livewire. Write to a temp file and stream that instead.
        $tempPath = tempnam(sys_get_temp_dir(), 'gws-financial-').'.pdf';
        file_put_contents($tempPath, $pdf->output());

        return response()->download($tempPath, 'financial-report-'.now()->format('Y-m-d-His').'.pdf')
            ->deleteFileAfterSend(true);
    }
}
