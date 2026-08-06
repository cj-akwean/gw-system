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
use Filament\Forms\Components\Placeholder;
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
                    ->options(function (string $operation, ?Model $record): array {
                        $methods = collect(PaymentService::OFFLINE_METHODS)
                            ->mapWithKeys(fn (string $method) => [$method => ucwords(str_replace('_', ' ', $method))]
                            )
                            ->toArray();

                        if ($record?->method && ! array_key_exists($record->method, $methods)) {
                            $methods[$record->method] = static::methodLabel($record->method, $record->paymongo_source);
                        }

                        return $methods;
                    })
                    ->default(PaymentService::OFFLINE_METHODS[0] ?? 'cash')
                    ->required(),

                TextInput::make('reference')
                    ->label('Reference / OR No.')
                    ->maxLength(100)
                    ->helperText('Official receipt or reference number, if the office issues one.')
                    ->formatStateUsing(fn (?string $state, ?Model $record): ?string => $state ?? $record?->paymongo_reference),

                Placeholder::make('paymongo_source')
                    ->label('PayMongo Channel')
                    ->content(fn (?Model $record): string => static::channelLabel($record?->paymongo_source))
                    ->visible(fn (string $operation, ?Model $record): bool => $operation === 'view' && $record?->method === 'paymongo'),

                Placeholder::make('payer')
                    ->label('Payer')
                    ->content(fn (?Model $record): string => static::payerLabel($record))
                    ->visible(fn (string $operation): bool => $operation === 'view'),

                Placeholder::make('processed_by_display')
                    ->label('Processed By')
                    ->content(fn (?Model $record): string => static::processedByLabel($record))
                    ->visible(fn (string $operation): bool => $operation === 'view'),

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
                    ->formatStateUsing(fn (Payment $record): string => static::methodLabel($record->method, $record->paymongo_source))
                    ->color(fn (Payment $record): string => $record->method === 'cash' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->getStateUsing(fn (Payment $record): string => $record->reference ?? $record->paymongo_reference ?? '—'),

                Tables\Columns\TextColumn::make('payer_name')
                    ->label('Payer')
                    ->default('—')
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('recordedBy.name')
                    ->label('Processed By')
                    ->getStateUsing(fn (Payment $record): string => static::processedByLabel($record))
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('method')
                    ->options(collect(PaymentService::OFFLINE_METHODS)
                        ->mapWithKeys(fn (string $method) => [$method => ucwords(str_replace('_', ' ', $method))])
                        ->put('paymongo', 'PayMongo')
                        ->toArray()),

                Tables\Filters\SelectFilter::make('invoice.status')
                    ->label('Invoice Status')
                    ->options([
                        'unpaid' => 'Unpaid',
                        'overdue' => 'Overdue',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'invoice',
                            fn (Builder $iq): Builder => $iq->where('status', $data['value']),
                        );
                    }),

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

    public static function methodLabel(?string $method, ?string $channel = null): string
    {
        $label = ucwords(str_replace('_', ' ', (string) $method));

        if ($method !== 'paymongo') {
            return $label;
        }

        return $channel ? 'PayMongo · '.self::channelLabel($channel) : 'PayMongo';
    }

    public static function channelLabel(?string $channel): string
    {
        $known = [
            'qrph' => 'QR Ph',
            'brankas' => 'Brankas',
            'card' => 'Card',
            'dob' => 'Direct Online Bank',
            'billease' => 'BillEase',
            'gcash' => 'GCash',
            'grab_pay' => 'Grab Pay',
            'shopee_pay' => 'Shopee Pay',
            'paymaya' => 'PayMaya',
        ];

        if ($channel === null || $channel === '') {
            return '—';
        }

        return $known[$channel] ?? ucwords(str_replace('_', ' ', $channel));
    }

    public static function processedByLabel(Payment $record): string
    {
        if ($record->recordedBy !== null) {
            return (string) $record->recordedBy->name;
        }

        return $record->method === 'paymongo' ? 'PayMongo' : '—';
    }

    private static function payerLabel(?Payment $record): string
    {
        if ($record === null || $record->payer_name === null) {
            return '—';
        }

        return collect([$record->payer_name, $record->payer_email, $record->payer_phone])
            ->filter(fn (?string $value): bool => $value !== null && $value !== '')
            ->implode(' · ');
    }
}
