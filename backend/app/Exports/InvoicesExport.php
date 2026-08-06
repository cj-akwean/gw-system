<?php

namespace App\Exports;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InvoicesExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        return $this->query
            ->clone()
            ->with(['serviceConnection', 'rateSchedule', 'meterReading'])
            ->orderByDesc('billing_period_end');
    }

    public function headings(): array
    {
        return [
            'invoice_number',
            'account_number',
            'meter_number',
            'customer_name',
            'status',
            'billing_period_start',
            'billing_period_end',
            'due_date',
            'previous_balance',
            'base_amount',
            'penalty_amount',
            'total_amount',
            'rate_schedule',
            'meter_reading_cu_m_used',
            'meter_reading_entered_at',
        ];
    }

    public function map($invoice): array
    {
        /** @var Invoice $invoice */
        $connection = $invoice->serviceConnection;
        $reading = $invoice->meterReading;

        return [
            $this->sanitize((string) $invoice->invoice_number),
            $this->sanitize((string) ($connection?->account_number ?? '')),
            $this->sanitize((string) ($connection?->meter_number ?? '')),
            $this->sanitize((string) ($connection?->registered_name ?? '')),
            $this->sanitize(ucfirst((string) $invoice->status)),
            $invoice->billing_period_start?->toDateString(),
            $invoice->billing_period_end?->toDateString(),
            $invoice->due_date?->toDateString(),
            number_format((float) $invoice->previous_balance, 2, '.', ''),
            number_format((float) $invoice->base_amount, 2, '.', ''),
            number_format((float) $invoice->penalty_amount, 2, '.', ''),
            number_format((float) $invoice->total_amount, 2, '.', ''),
            $this->sanitize((string) ($invoice->rateSchedule?->name ?? '')),
            $reading ? number_format((float) $reading->cu_m_used, 2, '.', '') : '',
            $reading?->entered_at?->toDateTimeString(),
        ];
    }

    private function sanitize(string $value): string
    {
        if (
            $value !== ''
            && in_array($value[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)
        ) {
            return "'".$value;
        }

        return $value;
    }
}