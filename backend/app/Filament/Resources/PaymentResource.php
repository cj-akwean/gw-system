<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentService;
use Carbon\Carbon;
use Closure;
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
use Illuminate\Database\Eloquent\Model;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'Payments';

    protected static ?string $recordTitleAttribute = 'id';

    public static function form(Schema $schema): Schema
    {
        $offlineMethods = collect(PaymentService::OFFLINE_METHODS)
            ->mapWithKeys(fn (string $method) => [$method => ucwords(str_replace('_', ' ', $method))]
            )
            ->toArray();

        return $schema
            ->components([
                Select::make('invoice_id')
                    ->label('Invoice')
                    ->placeholder('Unpaid / overdue invoice — search by #, account, meter, or name')
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, $set) {
                        $invoice = $state ? Invoice::find((int) $state) : null;
                        $set('amount', $invoice ? (string) round((float) $invoice->total_amount) : null);
                    })
                    ->getSearchResultsUsing(function (string $search): array {
                        return Invoice::query()
                            ->whereIn('status', ['unpaid', 'overdue'])
                            ->where(function (Builder $q) use ($search) {
                                $q->where('invoice_number', 'ilike', "%{$search}%")
                                    ->orWhereHas('serviceConnection', function (Builder $sc) use ($search) {
                                        $sc->where('account_number', 'ilike', "%{$search}%")
                                            ->orWhere('meter_number', 'ilike', "%{$search}%")
                                            ->orWhere('registered_name', 'ilike', "%{$search}%");
                                    });
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Invoice $invoice) => [
                                $invoice->id => static::invoiceLabel($invoice),
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelUsing(fn ($value): ?string => Invoice::find((int) $value) != null
                        ? static::invoiceLabel(Invoice::find((int) $value))
                        : null),

                TextInput::make('amount')
                    ->label('Amount Received (₱)')
                    ->numeric()
                    ->required()
                    ->helperText('Defaults to the nearest peso of the invoice total. Must be within ₱1.00 of the bill — PH cash payments rarely split centavos.')
                    ->minValue(0.01)
                    ->live()
                    ->afterStateUpdated(fn ($state, $set) => $set('amount', is_numeric($state) ? (string) round((float) $state, 2) : $state)),

                Select::make('method')
                    ->label('Payment Method')
                    ->options($offlineMethods)
                    ->default(PaymentService::OFFLINE_METHODS[0] ?? 'cash')
                    ->required(),

                TextInput::make('reference')
                    ->label('Reference / OR No.')
                    ->maxLength(100)
                    ->helperText('Official receipt or reference number, if the office issues one.'),

                DatePicker::make('paid_at')
                    ->label('Payment Date')
                    ->default(now())
                    ->required()
                    ->rules([
                        fn () => function (string $attribute, mixed $value, Closure $fail): void {
                            if ($value && Carbon::parse($value)->toDateString() > now()->toDateString()) {
                                $fail('Payment date cannot be in the future.');
                            }
                        },
                    ]),

                Hidden::make('recorded_by')
                    ->default(fn () => Filament::auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('invoice.serviceConnection.account_number')
                    ->label('Account')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice.serviceConnection.registered_name')
                    ->label('Registered Name')
                    ->searchable()
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                Tables\Columns\TextColumn::make('method')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucwords(str_replace('_', ' ', $state)))
                    ->color(fn (string $state): string => $state === 'cash' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label('Recorded By')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')
                    ->options(collect(PaymentService::OFFLINE_METHODS)
                        ->mapWithKeys(fn (string $method) => [$method => ucwords(str_replace('_', ' ', $method))])
                        ->toArray()),

                Tables\Filters\SelectFilter::make('invoice.status')
                    ->label('Invoice Status')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'overdue' => 'Overdue',
                    ]),

                Tables\Filters\Filter::make('paid_at')
                    ->form([
                        DatePicker::make('paid_from'),
                        DatePicker::make('paid_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['paid_from'],
                                fn (Builder $q, $date): Builder => $q->whereDate('paid_at', '>=', $date),
                            )
                            ->when(
                                $data['paid_until'],
                                fn (Builder $q, $date): Builder => $q->whereDate('paid_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('paid_at', 'desc')
            ->actions([
                Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'invoice.invoice_number',
            'invoice.serviceConnection.account_number',
            'invoice.serviceConnection.meter_number',
            'invoice.serviceConnection.registered_name',
        ];
    }

    protected static function invoiceLabel(Invoice $invoice): string
    {
        $connection = $invoice->serviceConnection;

        return sprintf(
            '#%s — %s (Acct %s) — Total ₱%s',
            $invoice->invoice_number,
            $connection?->registered_name ?? 'no connection',
            $connection?->account_number ?? '—',
            number_format((float) $invoice->total_amount, 2),
        );
    }
}
