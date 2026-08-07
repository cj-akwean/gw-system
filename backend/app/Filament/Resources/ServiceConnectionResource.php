<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceConnectionResource\Pages;
use App\Filament\Resources\ServiceConnectionResource\RelationManagers\ConnectionLinksRelationManager;
use App\Filament\Resources\ServiceConnectionResource\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\ServiceConnectionResource\RelationManagers\MeterReadingsRelationManager;
use App\Models\ServiceConnection;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ServiceConnectionResource extends Resource
{
    protected static ?string $model = ServiceConnection::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('account_number')
                    ->label('Account Number')
                    ->required()
                    ->maxLength(20)
                    ->unique('service_connections', 'account_number')
                    ->helperText('Used by customers to self-link their portal account. Changes notify linked users by email.'),

                TextInput::make('meter_number')
                    ->label('Meter Number')
                    ->required()
                    ->maxLength(20)
                    ->unique('service_connections', 'meter_number'),

                TextInput::make('registered_name')
                    ->label('Registered Name')
                    ->required()
                    ->maxLength(255),

                Select::make('barangay_id')
                    ->label('Barangay')
                    ->relationship('barangay', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('address')
                    ->label('Address')
                    ->required()
                    ->maxLength(255),

                TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(20)
                    ->rules(['nullable', 'regex:/^[0-9+\-() ]+$/'])
                    ->helperText('Applicant contact number.'),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255)
                    ->helperText('Applicant email.'),

                Select::make('gender')
                    ->label('Gender')
                    ->options([
                        'male' => 'Male',
                        'female' => 'Female',
                    ])
                    ->rules(['nullable', 'in:male,female']),

                DatePicker::make('birthdate')
                    ->label('Birthdate')
                    ->maxDate(today()),

                Select::make('civil_status')
                    ->label('Civil Status')
                    ->options([
                        'single' => 'Single',
                        'married' => 'Married',
                        'widowed' => 'Widowed',
                        'separated' => 'Separated',
                    ])
                    ->rules(['nullable', 'in:single,married,widowed,separated']),

                TextInput::make('occupation')
                    ->label('Occupation')
                    ->maxLength(100),

                Select::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'disconnected' => 'Disconnected',
                    ])
                    ->default('active')
                    ->required(),

                DatePicker::make('connection_date')
                    ->label('Connection Date')
                    ->required(),

                Select::make('rate_schedule_id')
                    ->label('Rate Schedule')
                    ->relationship('rateSchedule', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Empty = falls back to the globally active schedule when billing.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('meter_number')
                    ->label('Meter')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('registered_name')
                    ->label('Registered Name')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('barangay.name')
                    ->label('Barangay')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('pending_balance')
                    ->label('Balance')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => '₱'.number_format((float) ($state ?? 0), 2))
                    ->color(fn ($state): ?string => (float) ($state ?? 0) > 0 ? 'danger' : null),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'info',
                        'active' => 'success',
                        'inactive' => 'warning',
                        'disconnected' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('connection_date')
                    ->label('Connected')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('rateSchedule.name')
                    ->label('Rate')
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withPendingBalance())
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'disconnected' => 'Disconnected',
                    ]),

                Tables\Filters\SelectFilter::make('barangay')
                    ->relationship('barangay', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('account_number')
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [
            ConnectionLinksRelationManager::class,
            MeterReadingsRelationManager::class,
            InvoicesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceConnections::route('/'),
            'view' => Pages\ViewServiceConnection::route('/view/{record}'),
            'edit' => Pages\EditServiceConnection::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'account_number',
            'meter_number',
            'registered_name',
        ];
    }
}
