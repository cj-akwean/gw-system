<?php

namespace App\Exports;

use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PaymentsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(protected Builder $query)
    {
    }

    public function query(): Builder
    {
        return $this->query
            ->clone()
            ->with(['invoice.serviceConnection', 'recordedBy'])
            ->orderByDesc('paid_at');
    }

    public function headings(): array
    {
        return [
            'paid_at',
            'invoice_no',
            'account_no',
            'meter_no',
            'customer_name',
            'amount',
            'method',
            'reference',
            'payer_name',
            'payer_email',
            'recorded_by',
        ];
    }

    public function map($payment): array
    {
        /** @var Payment $payment */

        $invoice = $payment->invoice;
        $connection = $invoice?->serviceConnection;

        return [
            $payment->paid_at?->toDateTimeString(),
            $this->sanitize((string) ($invoice?->invoice_number ?? '')),
            $this->sanitize((string) ($connection?->account_number ?? '')),
            $this->sanitize((string) ($connection?->meter_number ?? '')),
            $this->sanitize((string) ($connection?->registered_name ?? '')),
            number_format((float) $payment->amount, 2, '.', ''),
            $this->sanitize(PaymentResource::methodLabel($payment->method, $payment->paymongo_source)),
            $this->sanitize((string) ($payment->reference ?? $payment->paymongo_reference ?? '')),
            $this->sanitize((string) ($payment->payer_name ?? '')),
            $this->sanitize((string) ($payment->payer_email ?? '')),
            $this->sanitize(PaymentResource::processedByLabel($payment)),
        ];
    }

    private function sanitize(string $value): string
    {
        if (
            $value !== ''
            && in_array($value[0], ['=', '+', '-', '@'], true)
        ) {
            return "'".$value;
        }

        return $value;
    }
}