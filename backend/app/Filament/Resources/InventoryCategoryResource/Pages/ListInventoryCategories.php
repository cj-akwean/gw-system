<?php

namespace App\Filament\Resources\InventoryCategoryResource\Pages;

use App\Filament\Resources\InventoryCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryCategories extends ListRecords
{
    protected static string $resource = InventoryCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
