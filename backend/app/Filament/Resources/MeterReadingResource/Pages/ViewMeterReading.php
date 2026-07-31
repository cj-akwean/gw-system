<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMeterReading extends ViewRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
