<?php

namespace App\Filament\Resources\ServiceConnectionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ConnectionLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'connectionLinks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('user.name')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Portal User')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('linked_at')
                    ->label('Linked At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('unlinked_at')
                    ->label('Unlinked At')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('linked_at', 'desc')
            ->bulkActions([]);
    }
}
