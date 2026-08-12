<?php

namespace App\Filament\Resources\InventoryItemResource\RelationManagers;

use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The audit ledger tab on an item's view page — every stock movement this
 * item ever had, append-only (no edit, no delete). New rows go through
 * InventoryService so quantity stays consistent and low-stock alerts fire.
 */
class InventoryTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * Filament 5 makes relation managers on view pages read-only by default
     * (panel-wide flag); this ledger tab must stay writable so admins can
     * record movements from the item's view page.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('moved_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'receipt' => 'success',
                        'issue' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 3)
                    ->getStateUsing(fn (InventoryTransaction $record): string => match ($record->type) {
                        'receipt' => '+'.$record->quantity,
                        'issue' => '-'.$record->quantity,
                        default => $record->quantity,
                    }),

                Tables\Columns\TextColumn::make('reference')
                    ->default('—')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('reason')
                    ->default('—')
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label('Recorded By')
                    ->default('—')
                    ->placeholder('—'),
            ])
            ->defaultSort('moved_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Record Movement')
                    ->modalHeading('Record stock movement')
                    ->modalSubmitActionLabel('Record')
                    ->modalWidth('md')
                    ->form([
                        Select::make('type')
                            ->options([
                                'receipt' => 'Receipt (stock in)',
                                'issue' => 'Issue (stock out)',
                                'adjustment' => 'Adjustment (correction)',
                            ])
                            ->default('receipt')
                            ->required()
                            ->live(),

                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->required()
                            ->helperText('Receipt/issue: positive. Adjustment: signed (e.g. 5 or -2).')
                            ->rules([
                                fn (callable $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                    if (! is_numeric($value)) {
                                        return;
                                    }
                                    $value = (float) $value;
                                    $type = $get('type');
                                    if ($type !== 'adjustment' && $value <= 0) {
                                        $fail('Must be greater than zero for receipts and issues.');
                                    }
                                    if ($type === 'adjustment' && $value == 0) {
                                        $fail('An adjustment cannot be zero.');
                                    }
                                    if (abs($value - round($value, 3)) > 1e-9) {
                                        $fail('Quantities allow at most 3 decimal places.');
                                    }
                                    if ($type === 'issue' && $value > (float) $this->getOwnerRecord()->quantity_on_hand) {
                                        $fail(sprintf(
                                            'Cannot issue %s — only %s %s available.',
                                            rtrim(rtrim(sprintf('%.3f', $value), '0'), '.'),
                                            rtrim(rtrim(sprintf('%.3f', (float) $this->getOwnerRecord()->quantity_on_hand), '0'), '.'),
                                            $this->getOwnerRecord()->unit,
                                        ));
                                    }
                                },
                            ]),

                        TextInput::make('reason')
                            ->label('Reason')
                            ->maxLength(255)
                            ->placeholder('e.g. physical count correction, work order #8')
                            ->helperText('Required for adjustments; optional for receipts and issues.')
                            ->live()
                            ->required(fn (callable $get): bool => $get('type') === 'adjustment'),

                        TextInput::make('reference')
                            ->label('Reference')
                            ->maxLength(100)
                            ->placeholder('e.g. PO #12, work order #8')
                            ->helperText('Optional — supplier / purchase order / work order.'),

                        DatePicker::make('moved_at')
                            ->label('Date')
                            ->default(now())
                            ->required()
                            ->rule('before_or_equal:today')
                            ->validationMessages([
                                'before_or_equal' => 'Movement date cannot be in the future.',
                            ]),
                    ])
                    ->using(function (array $data): InventoryTransaction {
                        try {
                            return app(InventoryService::class)->recordTransaction(
                                item: $this->getOwnerRecord(),
                                type: $data['type'],
                                quantity: (float) $data['quantity'],
                                reference: $data['reference'] ?? null,
                                reason: $data['reason'] ?? null,
                                recordedBy: Filament::auth()->id(),
                                movedAt: $data['moved_at'] ?? null,
                            );
                        } catch (InvalidArgumentException $exception) {
                            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
                        }
                    })
                    ->after(function (): void {
                        // Keep this component's view of the parent fresh so the
                        // overdraw rule and the parent page's quantity display
                        // never lag the ledger.
                        $this->getOwnerRecord()->refresh();
                        $this->dispatch('inventoryItemRefreshed', itemId: $this->getOwnerRecord()->getKey());
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }
}
