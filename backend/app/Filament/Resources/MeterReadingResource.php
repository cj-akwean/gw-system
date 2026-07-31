<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeterReadingResource\Pages;
use App\Models\MeterReading;
use App\Models\ServiceConnection;
use App\Services\ReadingService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Closure;

class MeterReadingResource extends Resource
{
    protected static ?string $model = MeterReading::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string | \UnitEnum | null $navigationGroup = 'Operations';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('service_connection_id')
                    ->label('Service Connection')
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) {
                        if (! $state) {
                            return;
                        }
                        $service = app(ReadingService::class);
                        $latest = $service->getLatestReading((int) $state);
                        if ($latest) {
                            $set('previous_reading', (string) $latest->present_reading);
                        }
                        $present = $get('present_reading');
                        if ($present !== null && $present !== '' && (float) $present < (float) $latest?->present_reading) {
                            $set('flagged', 2);
                        }
                    })
                    ->getSearchResultsUsing(function (string $search): array {
                        return ServiceConnection::query()
                            ->where(function (Builder $q) use ($search) {
                                $q->where('account_number', 'ilike', "%{$search}%")
                                    ->orWhere('meter_number', 'ilike', "%{$search}%")
                                    ->orWhere('registered_name', 'ilike', "%{$search}%");
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (ServiceConnection $sc) => [
                                $sc->id => "#{$sc->account_number} — {$sc->registered_name} (Meter: {$sc->meter_number})",
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => ServiceConnection::find($value)?->account_number),

                TextInput::make('present_reading')
                    ->label('Present Reading (cu.m.)')
                    ->required()
                    ->numeric()
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) {
                        $previous = (float) ($get('previous_reading') ?? 0);
                        $present = (float) ($state ?? 0);
                        $set('cu_m_used', (string) round($present - $previous, 2));
                        if ($state !== null && $state !== '' && $present < $previous) {
                            $set('flagged', 2);
                        }
                    }),

                TextInput::make('previous_reading')
                    ->label('Previous Reading (cu.m.)')
                    ->required()
                    ->numeric()
                    ->live()
                    ->afterStateUpdated(function ($state, $get, $set) {
                        $present = (float) ($get('present_reading') ?? 0);
                        $previous = (float) ($state ?? 0);
                        $set('cu_m_used', (string) round($present - $previous, 2));
                        $presentRaw = $get('present_reading');
                        if ($presentRaw !== null && $presentRaw !== '' && $present < $previous) {
                            $set('flagged', 2);
                        }
                    }),

                TextInput::make('cu_m_used')
                    ->label('Cu.m. Used')
                    ->required()
                    ->numeric()
                    ->readOnly()
                    ->dehydrated(true),

                DatePicker::make('entered_at')
                    ->label('Reading Date')
                    ->required()
                    ->default(now())
                    ->rules([
                        fn ($get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $service = app(ReadingService::class);
                            $connectionId = $get('service_connection_id');
                            $previousDate = $connectionId
                                ? $service->getLatestReading((int) $connectionId)?->entered_at?->toDateString()
                                : null;

                            foreach ($service->validateReadingDate($value, $previousDate) as $error) {
                                $fail($error);
                            }
                        },
                    ]),

                Select::make('flagged')
                    ->label('Flagged')
                    ->helperText('A present reading lower than previous always saves as Meter replacement (level 2).')
                    ->options([
                        0 => 'Not flagged',
                        1 => 'Flagged',
                        2 => 'Meter replacement (present < previous)',
                    ])
                    ->default(0),

                Hidden::make('entered_by')
                    ->default(fn () => Filament::auth()->id()),

                Hidden::make('method')
                    ->default('manual'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('serviceConnection.account_number')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('serviceConnection.meter_number')
                    ->label('Meter')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('serviceConnection.registered_name')
                    ->label('Registered Name')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('serviceConnection.barangay.name')
                    ->label('Barangay')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('present_reading')
                    ->label('Present')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('previous_reading')
                    ->label('Previous')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cu_m_used')
                    ->label('Cu.m.')
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->color(fn (MeterReading $record): ?string => $record->cu_m_used < 0 ? 'danger' : null),

                Tables\Columns\TextColumn::make('method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'manual' => 'primary',
                        'csv_import' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('flagged')
                    ->label('Flag')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        2 => 'Meter replacement',
                        1 => 'Flagged',
                        default => '—',
                    })
                    ->color(fn (int $state): string => match ($state) {
                        2 => 'danger',
                        1 => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('enteredBy.name')
                    ->label('Entered By')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('entered_at')
                    ->label('Reading Date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')
                    ->options([
                        'manual' => 'Manual',
                        'csv_import' => 'CSV Import',
                    ]),

                Tables\Filters\SelectFilter::make('flagged')
                    ->label('Flag')
                    ->options([
                        0 => 'Not flagged',
                        1 => 'Flagged',
                        2 => 'Meter replacement',
                    ]),

                Tables\Filters\SelectFilter::make('barangay')
                    ->relationship('serviceConnection.barangay', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('entered_at')
                    ->form([
                        DatePicker::make('entered_from'),
                        DatePicker::make('entered_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['entered_from'],
                                fn (Builder $q, $date): Builder => $q->whereDate('entered_at', '>=', $date),
                            )
                            ->when(
                                $data['entered_until'],
                                fn (Builder $q, $date): Builder => $q->whereDate('entered_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('entered_at', 'desc')
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeterReadings::route('/'),
            'create' => Pages\CreateMeterReading::route('/create'),
            'import' => Pages\ImportMeterReadings::route('/import'),
            'view' => Pages\ViewMeterReading::route('/{record}'),
            'edit' => Pages\EditMeterReading::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'serviceConnection.account_number',
            'serviceConnection.meter_number',
            'serviceConnection.registered_name',
        ];
    }
}
