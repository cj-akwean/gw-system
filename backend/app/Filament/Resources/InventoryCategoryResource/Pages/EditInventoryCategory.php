<?php

namespace App\Filament\Resources\InventoryCategoryResource\Pages;

use App\Filament\Resources\InventoryCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInventoryCategory extends EditRecord
{
    protected static string $resource = InventoryCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
