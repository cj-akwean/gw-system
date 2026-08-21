<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Exceptions\InvoiceNotPayableException;
use App\Filament\Resources\PaymentResource;
use App\Services\PaymentService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
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
        } catch (InvoiceNotPayableException $e) {
            // Domain error → surface it inline on the Invoice field so the
            // operator sees exactly which field is wrong, not just a banner.
            throw ValidationException::withMessages([
                'data.invoice_id' => $this->friendlyPayabilityError($e->getMessage()),
            ]);
        } catch (InvalidArgumentException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            throw $e;
        }
    }

    /**
     * The exception message is "Invoice {id} (status: {status}) is not
     * payable." — turn the status into an operator-facing explanation.
     */
    private function friendlyPayabilityError(string $raw): string
    {
        if (preg_match('/status:\s*([a-z_]+)/i', $raw, $matches) === 1) {
            $status = strtolower($matches[1]);

            if ($status === 'paid') {
                return 'This invoice is already paid — pick an unpaid or overdue one.';
            }
        }

        return 'This invoice is not payable. Pick an unpaid or overdue one.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
