<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Exports\InvoicesExport;
use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): BinaryFileResponse {
                    return Excel::download(
                        new InvoicesExport($this->getTableQueryForExport()),
                        'invoices-' . now()->format('Y-m-d-His') . '.csv',
                    );
                }),
        ];
    }
}
