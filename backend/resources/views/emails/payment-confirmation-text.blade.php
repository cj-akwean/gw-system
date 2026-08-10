{{ config('mail.from.name') }}

Payment received — ₱{{ number_format($payment->amount, 2) }}
Paid {{ $payment->paid_at?->format('F j, Y') ?? now()->format('F j, Y') }}

Your itemized invoice is attached as a PDF for your records.

Invoice number:  {{ $invoice->invoice_number }}
Customer:        {{ $invoice->serviceConnection?->registered_name ?? '—' }}
Account No.:     {{ $invoice->serviceConnection?->account_number ?? '—' }}
Meter No.:       {{ $invoice->serviceConnection?->meter_number ?? '—' }}
Billing period:  {{ $invoice->billing_period_start?->format('M d, Y') ?? '—' }} – {{ $invoice->billing_period_end?->format('M d, Y') ?? '—' }}
Payment method:  {{ $paymentMethodLabel }}
Payer:           {{ $payment->payer_name ?? '—' }}{{ $payment->payer_email !== null ? ' · '.$payment->payer_email : '' }}{{ $payment->payer_phone !== null ? ' · '.$payment->payer_phone : '' }}
Reference:       {{ $payment->reference ?? $payment->paymongo_reference ?? '—' }}

Payment details
Current charges  ₱{{ number_format($invoice->base_amount, 2) }}
Arrears          ₱{{ number_format($invoice->previous_balance, 2) }}
Penalty          ₱{{ number_format($invoice->penalty_amount, 2) }}
Total            ₱{{ number_format($invoice->total_amount, 2) }}
Amount paid      ₱{{ number_format($payment->amount, 2) }}

Questions? Contact us at {{ config('mail.from.address') }} or call (052) 000-0000.

Guinobatan Waterworks · Guinobatan, Albay
