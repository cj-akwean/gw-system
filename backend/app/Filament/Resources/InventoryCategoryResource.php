<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryCategoryResource\Pages;
use App\Models\InventoryCategory;
use Closure;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class InventoryCategoryResource extends Resource
{
    protected static ?string $model = InventoryCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Category Name')
                    ->required()
                    ->maxLength(100)
                    ->rules([
                        fn (?Model $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (is_string($value) && trim($value) !== '' && InventoryCategory::isNameTaken($value, $record?->getKey())) {
                                $fail('A category with this name already exists.');
                            }
                        },
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->before(function (Actions\DeleteAction $action, Model $record): void {
                        // A category in use by items must stay — deleting it
                        // would orphan the items' grouping. Tell the admin why
                        // instead of silently hiding the button.
                        $itemCount = $record->items()->count();

                        if ($itemCount > 0) {
                            Notification::make()
                                ->title('Category in use')
                                ->body("This category has {$itemCount} item(s). Rename it instead of deleting.")
                                ->warning()
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryCategories::route('/'),
            'create' => Pages\CreateInventoryCategory::route('/create'),
            'edit' => Pages\EditInventoryCategory::route('/{record}/edit'),
        ];
    }
}
