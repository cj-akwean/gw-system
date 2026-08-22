<?php

namespace App\Filament\Resources\ServiceConnectionResource\RelationManagers;

use App\Models\MeterReading;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class MeterReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meterReadings';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->recordTitle(function (MeterReading $record): string {
                $account = $record->serviceConnection?->account_number ?? '—';
                $date = $record->entered_at?->format('M j, Y') ?? '—';

                return "Reading — {$account} ({$date})";
            })
            ->columns([
                Tables\Columns\TextColumn::make('entered_at')
                    ->label('Date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('present_reading')
                    ->label('Present')
                    ->numeric(decimalPlaces: 2),

                Tables\Columns\TextColumn::make('previous_reading')
                    ->label('Previous')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cu_m_used')
                    ->label('Cu.m.')
                    ->numeric(decimalPlaces: 2)
                    ->color(fn ($record): ?string => $record->cu_m_used < 0 ? 'danger' : null),

                Tables\Columns\TextColumn::make('flagged')
                    ->label('Flag')
                    ->badge()
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

                Tables\Columns\TextColumn::make('method')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'manual' ? 'primary' : 'gray'),

                Tables\Columns\TextColumn::make('enteredBy.name')
                    ->label('Entered By')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('entered_at', 'desc');
    }
}
