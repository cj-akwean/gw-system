<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Services\InventoryService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateInventoryItem extends CreateRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function afterCreate(): void
    {
        $initial = (float) ($this->data['initial_quantity'] ?? 0);

        if ($initial <= 0) {
            return;
        }

        try {
            app(InventoryService::class)->recordTransaction(
                item: $this->record,
                type: 'receipt',
                quantity: $initial,
                reference: 'Opening stock',
                recordedBy: Filament::auth()->id(),
                alert: false,
            );
        } catch (InvalidArgumentException $exception) {
            // Never leave a half-created item behind when the opening
            // receipt fails (e.g. > 3 decimal places).
            $this->record->delete();

            throw ValidationException::withMessages(['initial_quantity' => $exception->getMessage()]);
        }
    }
}
