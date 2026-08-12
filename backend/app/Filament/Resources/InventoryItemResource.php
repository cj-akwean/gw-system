<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryItemResource\Pages;
use App\Filament\Resources\InventoryItemResource\RelationManagers\InventoryTransactionsRelationManager;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use Closure;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryItemResource extends Resource
{
    protected static ?string $model = InventoryItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-cart';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('inventory_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Select a category, or create a new one right from this form.')
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('New Category Name')
                            ->required()
                            ->maxLength(100)
                            ->rules([
                                fn () => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (is_string($value) && trim($value) !== '' && InventoryCategory::isNameTaken($value)) {
                                        $fail('A category with this name already exists.');
                                    }
                                },
                            ]),
                    ]),

                TextInput::make('name')
                    ->label('Item Name')
                    ->required()
                    ->maxLength(200)
                    ->placeholder('e.g. Lion PVC Pipe 40mm × 40mm — ₱3,240')
                    ->helperText('Free text — brand, size, and even price can live here. Categories sort items; this name identifies the item.')
                    ->rules([
                        fn (?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (is_string($value) && trim($value) !== '' && InventoryItem::isNameTaken($value, $record?->getKey())) {
                                $fail('An item with this name already exists.');
                            }
                        },
                    ]),

                Select::make('unit')
                    ->label('Unit')
                    ->options([
                        'pc' => 'Piece (pc)',
                        'm' => 'Meter (m)',
                        'roll' => 'Roll',
                        'bag' => 'Bag',
                        'set' => 'Set',
                        'box' => 'Box',
                        'kg' => 'Kilogram (kg)',
                        'L' => 'Liter (L)',
                    ])
                    ->default('pc')
                    ->required(),

                TextInput::make('reorder_level')
                    ->label('Reorder Level')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->helperText('Required — the admin bell rings when the quantity drops below this level. Set 0 only if this item should never alert.'),

                TextInput::make('initial_quantity')
                    ->label('Initial Quantity')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->helperText('Opening stock — recorded as a receipt transaction with this admin as recorder.')
                    ->rules([
                        fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            if (is_numeric($value) && abs((float) $value - round((float) $value, 3)) > 1e-9) {
                                $fail('Quantities allow at most 3 decimal places.');
                            }
                        },
                    ])
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                TextInput::make('quantity_on_hand')
                    ->label('Quantity On Hand')
                    ->numeric()
                    ->readOnly()
                    ->visible(fn (string $operation): bool => $operation === 'view'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit'),

                Tables\Columns\TextColumn::make('quantity_on_hand')
                    ->label('On Hand')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                Tables\Columns\TextColumn::make('reorder_level')
                    ->label('Reorder Level')
                    ->numeric(decimalPlaces: 3)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (InventoryItem $record): string => match ($record->status()) {
                        'no_stock' => 'No stock',
                        'low_stock' => 'Low stock',
                        default => 'OK',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'No stock' => 'danger',
                        'Low stock' => 'warning',
                        default => 'success',
                    })
                    ->sortable()
                    ->sortable(query: fn (Builder $query) => $query->orderByRaw('CASE WHEN quantity_on_hand <= 0 THEN 0 WHEN quantity_on_hand < reorder_level THEN 1 ELSE 2 END')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('inventory_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('low_stock')
                    ->label('Low stock only')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('quantity_on_hand', '<', 'reorder_level')),

                Tables\Filters\Filter::make('no_stock')
                    ->label('No stock only')
                    ->query(fn (Builder $query): Builder => $query->where('quantity_on_hand', '<=', 0)),
            ])
            ->defaultSort('name')
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canDelete(Model $record): bool
    {
        // A ledger is append-only: once an item has stock history it must
        // never be deleted (same rule payments follow).
        return ! $record->transactions()->exists();
    }

    public static function getRelations(): array
    {
        return [
            InventoryTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryItems::route('/'),
            'create' => Pages\CreateInventoryItem::route('/create'),
            'view' => Pages\ViewInventoryItem::route('/{record}'),
            'edit' => Pages\EditInventoryItem::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'category.name'];
    }
}
