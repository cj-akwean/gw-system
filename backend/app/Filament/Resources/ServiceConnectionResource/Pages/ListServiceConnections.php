<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Exports\ServiceConnectionsExport;
use App\Filament\Resources\ServiceConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListServiceConnections extends ListRecords
{
    protected static string $resource = ServiceConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('importCsv')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-on-square')
                ->url(ServiceConnectionResource::getUrl('import')),

            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): BinaryFileResponse {
                    return Excel::download(
                        new ServiceConnectionsExport($this->getTableQueryForExport()),
                        'service-connections-'.now()->format('Y-m-d-His').'.csv',
                    );
                }),
        ];
    }
}
