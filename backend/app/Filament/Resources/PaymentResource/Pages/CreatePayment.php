<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Exceptions\InvoiceNotPayableException;
use App\Filament\Resources\PaymentResource;
use App\Services\PaymentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PaymentService::class)->recordOfflinePayment(
                invoiceId: (int) $data['invoice_id'],
                amount: (float) $data['amount'],
                reference: $data['reference'] ?? null,
                paidAt: $data['paid_at'] ?? now()->toDateTimeString(),
                recordedBy: isset($data['recorded_by']) ? (int) $data['recorded_by'] : null,
                method: $data['method'] ?? 'cash',
            );
        } catch (InvoiceNotPayableException|InvalidArgumentException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw $e;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
