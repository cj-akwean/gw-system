<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BillingRunResource\Pages;
use App\Models\BillingRun;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BillingRunResource extends Resource
{
    protected static ?string $model = BillingRun::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * Human-readable record title for headings ("View Run #8" instead of
     * "View 8").
     */
    public static function getRecordTitle(?Model $record): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        if ($record instanceof BillingRun) {
            $period = $record->period_end?->format('M Y') ?? '';

            return 'Run #'.$record->id.($period ? " — {$period}" : '');
        }

        return parent::getRecordTitle($record);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run Summary')
                    ->poll('5s')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('id')
                                    ->label('Run #'),

                                TextEntry::make('period_end')
                                    ->label('Billing Period')
                                    ->date('M j, Y'),

                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->color(fn (string $state): string => match ($state) {
                                        'running' => 'info',
                                        'completed' => 'success',
                                        'failed' => 'danger',
                                        default => 'gray',
                                    }),

                                TextEntry::make('created_at')
                                    ->label('Started')
                                    ->dateTime('M j, Y g:i A'),

                                TextEntry::make('finished_at')
                                    ->label('Finished')
                                    ->dateTime('M j, Y g:i A')
                                    ->placeholder('—'),

                                TextEntry::make('invoice_count')
                                    ->label('Invoices billed')
                                    ->getStateUsing(fn (BillingRun $record): int => collect($record->report)->where('status', 'billed')->count()),
                            ]),

                        TextEntry::make('error')
                            ->label('Error')
                            ->color('danger')
                            ->visible(fn (BillingRun $record): bool => filled($record->error)),
                    ]),

                Section::make(fn (BillingRun $record): string => 'Per-connection report ('.count($record->report ?? []).' rows)')
                    ->poll('5s')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        RepeatableEntry::make('report')
                            ->schema([
                                TextEntry::make('account_number')
                                    ->label('Account'),

                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                                    ->color(fn (string $state): string => $state === 'billed' ? 'success' : 'warning'),

                                TextEntry::make('reason')
                                    ->label('Reason'),

                                TextEntry::make('invoice_number')
                                    ->label('Invoice'),

                                TextEntry::make('total_amount')
                                    ->label('Total (₱)')
                                    ->formatStateUsing(fn ($state): string => $state !== null ? number_format((float) $state, 2) : '—'),
                            ])
                            ->table([
                                TableColumn::make('Account'),
                                TableColumn::make('Status'),
                                TableColumn::make('Reason'),
                                TableColumn::make('Invoice'),
                                TableColumn::make('Total (₱)'),
                            ])
                            ->placeholder('No report rows — the run finished without processing any connection.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Run #')
                    ->sortable(),

                Tables\Columns\TextColumn::make('period_end')
                    ->label('Period')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'running' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoice_count')
                    ->label('Billed')
                    ->getStateUsing(fn (BillingRun $record): int => collect($record->report)->where('status', 'billed')->count()),

                Tables\Columns\TextColumn::make('error')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->actions([
                ViewAction::make(),
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
            'index' => Pages\ListBillingRuns::route('/'),
            'view' => Pages\ViewBillingRun::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'period_end',
            'status',
            'error',
        ];
    }
}
