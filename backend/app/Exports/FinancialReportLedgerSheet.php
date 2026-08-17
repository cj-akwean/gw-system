<?php

namespace App\Exports;

use App\Exports\Concerns\SanitizesCsvFields;
use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class FinancialReportLedgerSheet implements FromQuery, WithHeadings, WithMapping, WithTitle
{
    use SanitizesCsvFields;

    public function __construct(
        protected ?string $from = null,
        protected ?string $to = null,
    ) {}

    public function title(): string
    {
        return 'Payments Ledger';
    }

    public function query(): Builder
    {
        return Payment::query()
            ->with(['invoice.serviceConnection'])
            ->when($this->from, fn (Builder $q, string $from): Builder => $q->whereDate('paid_at', '>=', $from))
            ->when($this->to, fn (Builder $q, string $to): Builder => $q->whereDate('paid_at', '<=', $to))
            ->orderByDesc('paid_at');
    }

    public function headings(): array
    {
        return [
            'Transaction ID',
            'Invoice #',
            'Account #',
            'Customer Name',
            'Payment Date',
            'Payment Method',
            'Amount (PHP)',
            'Status / Reference #',
        ];
    }

    public function map($payment): array
    {
        /** @var Payment $payment */
        $invoice = $payment->invoice;
        $connection = $invoice?->serviceConnection;

        return [
            $payment->id,
            $this->sanitize((string) ($invoice?->invoice_number ?? '')),
            $this->sanitize((string) ($connection?->account_number ?? '')),
            $this->sanitize((string) ($connection?->registered_name ?? '')),
            $payment->paid_at?->toDateTimeString() ?? '',
            $this->sanitize(PaymentResource::methodLabel($payment->method, $payment->paymongo_source)),
            number_format((float) $payment->amount, 2, '.', ''),
            $this->sanitize((string) ($payment->reference ?? $payment->paymongo_reference ?? '—')),
        ];
    }
}
