<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Exports\ServiceConnectionsExport;
use App\Filament\Resources\ServiceConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListServiceConnections extends ListRecords
{
    protected static string $resource = ServiceConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): \Symfony\Component\HttpFoundation\BinaryFileResponse {
                    return Excel::download(
                        new ServiceConnectionsExport($this->getTableQueryForExport()),
                        'service-connections-'.now()->format('Y-m-d-His').'.csv',
                    );
                }),
        ];
    }
}