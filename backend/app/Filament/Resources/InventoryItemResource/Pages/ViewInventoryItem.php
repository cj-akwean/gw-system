<?php

namespace App\Filament\Resources\InventoryItemResource\Pages;

use App\Filament\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Services\InventoryService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Livewire\Attributes\On;

class ViewInventoryItem extends ViewRecord
{
    protected static string $resource = InventoryItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->stockAction('addStock', 'Add Stock', 'receipt', 'heroicon-o-plus-circle')
                ->modalDescription(fn (InventoryItem $record): string => sprintf(
                    'Current on hand: %s %s. Records a receipt into the ledger.',
                    rtrim(rtrim(sprintf('%.3f', (float) $record->quantity_on_hand), '0'), '.'),
                    $record->unit,
                )),

            $this->stockAction('removeStock', 'Remove Stock', 'issue', 'heroicon-o-minus-circle')
                ->color('warning')
                ->modalDescription(fn (InventoryItem $record): string => sprintf(
                    'Current on hand: %s %s. Records an issue (usage) into the ledger; cannot go below zero.',
                    rtrim(rtrim(sprintf('%.3f', (float) $record->quantity_on_hand), '0'), '.'),
                    $record->unit,
                )),

            Actions\EditAction::make(),
        ];
    }

    private function stockAction(string $name, string $label, string $type, string $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->modalHeading($label)
            ->modalSubmitActionLabel($label)
            ->modalWidth('md')
            ->form([
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->rules([
                        fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (is_numeric($value) && abs((float) $value - round((float) $value, 3)) > 1e-9) {
                                $fail('Quantities allow at most 3 decimal places.');
                            }
                        },
                    ]),

                TextInput::make('reason')
                    ->label('Reason')
                    ->maxLength(255)
                    ->placeholder($type === 'receipt' ? 'e.g. PO #12 delivery, supplier' : 'e.g. work order #8, repair at Purok 3')
                    ->helperText('Optional — what this movement is for.'),

                TextInput::make('reference')
                    ->label('Reference')
                    ->maxLength(100)
                    ->placeholder($type === 'receipt' ? 'e.g. PO #12, Supplier name' : 'e.g. Work order #8, repair at …')
                    ->helperText('Optional — supplier / purchase order / work order this movement belongs to.'),

                DatePicker::make('moved_at')
                    ->label('Date')
                    ->default(now())
                    ->required()
                    ->rule('before_or_equal:today')
                    ->validationMessages([
                        'before_or_equal' => 'Movement date cannot be in the future.',
                    ]),
            ])
            ->action(function (InventoryItem $record, array $data) use ($type, $label): void {
                try {
                    app(InventoryService::class)->recordTransaction(
                        item: $record,
                        type: $type,
                        quantity: (float) $data['quantity'],
                        reason: $data['reason'] ?? null,
                        reference: $data['reference'] ?? null,
                        recordedBy: Filament::auth()->id(),
                        movedAt: $data['moved_at'] ?? null,
                    );

                    $record->refresh();
                    $this->record = $record;

                    Notification::make()
                        ->title($label.' recorded')
                        ->body(sprintf(
                            'New on hand: %s %s.',
                            rtrim(rtrim(sprintf('%.3f', (float) $record->quantity_on_hand), '0'), '.'),
                            $record->unit,
                        ))
                        ->success()
                        ->send();
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title($label.' failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    #[On('inventoryItemRefreshed')]
    public function refreshItem(int $itemId): void
    {
        if ($this->record instanceof InventoryItem && $this->record->id === $itemId) {
            $this->record->refresh();
            $this->refreshFormData(['quantity_on_hand']);
        }
    }
}
