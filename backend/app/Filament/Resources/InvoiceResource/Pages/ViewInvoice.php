<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Exceptions\InvoiceNotPayableException;
use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\PaymentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markPaid')
                ->label('Mark Paid')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (Invoice $record): bool => in_array($record->status, ['unpaid', 'overdue'], true))
                ->modalHeading('Record offline payment')
                ->modalDescription('Record a cash/over-the-counter payment for this invoice.')
                ->modalSubmitActionLabel('Record Payment')
                ->modalWidth('md')
                ->fillForm(fn (Invoice $record): array => [
                    'amount' => $record ? (string) round((float) $record->total_amount) : null,
                    'method' => PaymentService::OFFLINE_METHODS[0] ?? 'cash',
                    'paid_at' => now()->toDateString(),
                ])
                ->form([
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
                        ->options(collect(PaymentService::OFFLINE_METHODS)
                            ->mapWithKeys(fn (string $method) => [$method => ucwords(str_replace('_', ' ', $method))])
                            ->toArray())
                        ->default(PaymentService::OFFLINE_METHODS[0] ?? 'cash')
                        ->required(),

                    TextInput::make('reference')
                        ->label('Reference / OR No.')
                        ->maxLength(100)
                        ->helperText('Official receipt or reference number, if the office issues one.')
                        ->placeholder('OR-2026-001'),

                    DatePicker::make('paid_at')
                        ->label('Payment Date')
                        ->default(now())
                        ->required()
                        ->rule('before_or_equal:today')
                        ->validationMessages([
                            'before_or_equal' => 'Payment date cannot be in the future.',
                        ]),
                ])
                ->action(function (Invoice $record, array $data): void {
                    try {
                        app(PaymentService::class)->recordOfflinePayment(
                            invoiceId: $record->id,
                            amount: (float) $data['amount'],
                            reference: $data['reference'] ?? null,
                            paidAt: $data['paid_at'] ?? null,
                            recordedBy: Filament::auth()->id(),
                            method: $data['method'] ?? 'cash',
                        );

                        Notification::make()
                            ->title('Payment Recorded')
                            ->body("Invoice #{$record->invoice_number} marked paid.")
                            ->success()
                            ->send();

                        $record->refresh();
                        $this->record = $record;
                        $this->refreshFormData(['status', 'serviceConnection', 'meterReading', 'rateSchedule']);
                    } catch (InvoiceNotPayableException|InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('Payment Failed')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
