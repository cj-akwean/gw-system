<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceConnection extends ViewRecord
{
    protected static string $resource = ServiceConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
