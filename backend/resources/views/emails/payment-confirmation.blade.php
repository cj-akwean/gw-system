<x-mail::message>
# Payment received

Your payment for invoice **{{ $invoice->invoice_number }}** has been received and confirmed.

<x-mail::table>
| | |
|---|---|
| Invoice number | {{ $invoice->invoice_number }} |
| Billing period | {{ $invoice->billing_period_start?->format('M d, Y') }} – {{ $invoice->billing_period_end?->format('M d, Y') }} |
| Amount paid | ₱{{ number_format($payment->amount, 2) }} |
| Invoice total | ₱{{ number_format($invoice->total_amount, 2) }} |
| Date paid | {{ $payment->paid_at?->format('M d, Y') }} |
</x-mail::table>

The itemized invoice is attached as a PDF for your records.

Thanks,<br>
{{ config('mail.from.name') }}
</x-mail::message>
