<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentPaymentsWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Payment::query()
                    ->with(['invoice.serviceConnection'])
                    ->orderByDesc('paid_at')
                    ->limit(8),
            )
            ->columns([
                TextColumn::make('invoice.invoice_number')
                    ->label('Invoice')
                    ->url(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record])),
                TextColumn::make('invoice.serviceConnection.account_number')
                    ->label('Account'),
                TextColumn::make('invoice.serviceConnection.registered_name')
                    ->label('Customer')
                    ->wrap()
                    ->limit(30),
                TextColumn::make('method')
                    ->label('Method')
                    ->badge()
                    ->formatStateUsing(fn (Payment $record): string => PaymentResource::methodLabel($record->method, $record->paymongo_source))
                    ->color(fn (Payment $record): string => $record->method === 'cash' ? 'success' : 'gray'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn (Payment $record): string => '₱'.number_format((float) $record->amount, 2)),
                TextColumn::make('paid_at')
                    ->label('Paid At')
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}