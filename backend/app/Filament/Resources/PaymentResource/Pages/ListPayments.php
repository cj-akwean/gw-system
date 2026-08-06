<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Exports\PaymentsExport;
use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): \Symfony\Component\HttpFoundation\BinaryFileResponse {
                    return Excel::download(
                        new PaymentsExport($this->getTableQueryForExport()),
                        'payments-'.now()->format('Y-m-d-His').'.csv',
                    );
                }),
        ];
    }
}
