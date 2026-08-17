<?php

namespace App\Filament\Pages;

use App\Exports\FinancialReportExport;
use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use App\Services\FinancialReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Accounting & Financial Management: AR aging, statement of income and a
 * payment reconciliation ledger for a selectable date range, with Excel and
 * PDF exports. The numbers are built by FinancialReportService so the page,
 * the spreadsheet and the PDF always show the same data. Operational KPIs
 * (active customers, unpaid/overdue counts, revenue chart) live on the
 * Dashboard only.
 */
class FinancialReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Accounting & Finance';

    protected static ?string $title = 'Accounting & Financial Management';

    protected static ?string $slug = 'financial-report';

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.financial-report';

    #[Url]
    public string $preset = 'monthly';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * Table filter state bound to the `filters` query param, mirroring
     * Resource list pages (ListRecords re-declares the trait's property with
     * #[Url(as: 'filters')]); a plain Page does not get that binding by
     * default, which would silently drop the ledger's method/date filters.
     */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public function mount(): void
    {
        if ($this->from === null || $this->to === null) {
            $this->preset = 'monthly';
            $this->updatedPreset('monthly');
        }
    }

    public function updatedPreset(string $value): void
    {
        $range = match ($value) {
            'quarterly' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'yearly' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $this->from = $range[0]->toDateString();
        $this->to = $range[1]->toDateString();
    }

    public function updatedFrom(string $value): void
    {
        $this->preset = filled($value) ? 'custom' : $this->preset;
    }

    public function updatedTo(string $value): void
    {
        $this->preset = filled($value) ? 'custom' : $this->preset;
    }

    public function reportData(): array
    {
        return app(FinancialReportService::class)->build($this->from, $this->to);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Payment::query()->with(['invoice.serviceConnection']))
            ->columns([
                TextColumn::make('id')
                    ->label('Transaction ID')
                    ->sortable(),

                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('invoice.serviceConnection.registered_name')
                    ->label('Customer Name')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('paid_at')
                    ->label('Payment Date')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('method')
                    ->label('Payment Method')
                    ->badge()
                    ->formatStateUsing(fn (Payment $record): string => PaymentResource::methodLabel($record->method, $record->paymongo_source))
                    ->color(fn (Payment $record): string => $record->method === 'cash' ? 'success' : 'gray'),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('reference')
                    ->label('Status / Reference #')
                    ->getStateUsing(fn (Payment $record): string => $record->reference ?? $record->paymongo_reference ?? '—'),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label('Payment Method')
                    ->options([
                        'cash' => 'Cash',
                        'paymongo' => 'Online (PayMongo)',
                        'bank_transfer' => 'Bank Transfer',
                    ]),

                Filter::make('paid_at')
                    ->label('Payment Date')
                    ->form([
                        DatePicker::make('paid_from'),
                        DatePicker::make('paid_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['paid_from'],
                                fn (Builder $q, string $date): Builder => $q->whereDate('paid_at', '>=', $date),
                            )
                            ->when(
                                $data['paid_until'],
                                fn (Builder $q, string $date): Builder => $q->whereDate('paid_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('paid_at', 'desc');
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => Excel::download(
                    new FinancialReportExport(app(FinancialReportService::class), $this->from, $this->to),
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
        $service = app(FinancialReportService::class);
        $data = $this->reportData();
        $range = $service->normalizeRange($this->from, $this->to);
        $data['ledger'] = $service->ledgerRows($range['from'], $range['to']);

        $pdf = Pdf::loadHTML(view('pdfs.financial-report', $data)->render())
            ->setPaper('a4', 'landscape')
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
