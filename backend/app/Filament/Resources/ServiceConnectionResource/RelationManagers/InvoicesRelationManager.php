<?php

namespace App\Filament\Resources\ServiceConnectionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_number')
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('billing_period_end')
                    ->label('Period')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_amount')
                    ->label('Charges')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('previous_balance')
                    ->label('Arrears')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('penalty_amount')
                    ->label('Penalty')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'unpaid' => 'warning',
                        'overdue' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('billing_period_end', 'desc')
            ->bulkActions([]);
    }
}
