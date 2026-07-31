<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMeterReadings extends ListRecords
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('import')
                ->label('Import CSV')
                ->icon('heroicon-o-arrow-up-on-square')
                ->url(MeterReadingResource::getUrl('import')),
        ];
    }
}
