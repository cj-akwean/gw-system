<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fieldset::make('Invoice Details')
                    ->schema([
                        Placeholder::make('invoice_number')
                            ->label('Invoice #')
                            ->content(fn (?Invoice $record): string => $record?->invoice_number ?? '—'),

                        Placeholder::make('account_number')
                            ->label('Account #')
                            ->content(fn (?Invoice $record): string => $record?->serviceConnection?->account_number ?? '—'),

                        Placeholder::make('meter_number')
                            ->label('Meter #')
                            ->content(fn (?Invoice $record): string => $record?->serviceConnection?->meter_number ?? '—'),

                        Placeholder::make('registered_name')
                            ->label('Registered Name')
                            ->content(fn (?Invoice $record): string => $record?->serviceConnection?->registered_name ?? '—'),
                    ]),

                Fieldset::make('Billing Period')
                    ->schema([
                        Placeholder::make('billing_period')
                            ->label('Period')
                            ->content(fn (?Invoice $record): string => $record
                                ? $record->billing_period_start->toFormattedDateString().' → '.$record->billing_period_end->toFormattedDateString()
                                : '—'),

                        Placeholder::make('due_date')
                            ->label('Due Date')
                            ->content(fn (?Invoice $record): string => $record?->due_date?->toFormattedDateString() ?? '—'),
                    ]),

                Fieldset::make('Charges')
                    ->schema([
                        Placeholder::make('base_amount')
                            ->label('Current Charges')
                            ->content(fn (?Invoice $record): string => $record ? '₱'.number_format((float) $record->base_amount, 2) : '—'),

                        Placeholder::make('previous_balance')
                            ->label('Arrears')
                            ->content(fn (?Invoice $record): string => $record ? '₱'.number_format((float) $record->previous_balance, 2) : '—'),

                        Placeholder::make('penalty_amount')
                            ->label('Penalty')
                            ->content(fn (?Invoice $record): string => $record ? '₱'.number_format((float) $record->penalty_amount, 2) : '—'),

                        Placeholder::make('total_amount')
                            ->label('Total')
                            ->content(fn (?Invoice $record): string => $record ? '₱'.number_format((float) $record->total_amount, 2) : '—')
                            ->extraAttributes(['class' => 'font-bold text-lg']),
                    ]),

                Fieldset::make('Metadata')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(fn (?Invoice $record): string => $record ? ucfirst($record->status) : '—'),

                        Placeholder::make('rate_schedule')
                            ->label('Rate Schedule')
                            ->content(fn (?Invoice $record): string => $record?->rateSchedule?->name ?? '—'),

                        Placeholder::make('meter_reading')
                            ->label('Meter Reading')
                            ->content(fn (?Invoice $record): string => $record?->meterReading
                                ? $record->meterReading->entered_at->toFormattedDateString().' · '.$record->meterReading->cu_m_used.' cu.m.'
                                : '—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('serviceConnection.account_number')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('serviceConnection.registered_name')
                    ->label('Customer')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'unpaid' => 'warning',
                        'overdue' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('billing_period_end')
                    ->label('Period')
                    ->date()
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['serviceConnection', 'rateSchedule', 'meterReading']))
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'overdue' => 'Overdue',
                        'paid' => 'Paid',
                    ])
                    ->multiple(),

                Filter::make('due_date')
                    ->form([
                        DatePicker::make('due_from'),
                        DatePicker::make('due_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['due_from'],
                                fn (Builder $q, $date): Builder => $q->whereDate('due_date', '>=', $date),
                            )
                            ->when(
                                $data['due_until'],
                                fn (Builder $q, $date): Builder => $q->whereDate('due_date', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('billing_period_end', 'desc')
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'invoice_number',
            'serviceConnection.account_number',
            'serviceConnection.meter_number',
            'serviceConnection.registered_name',
        ];
    }
}
