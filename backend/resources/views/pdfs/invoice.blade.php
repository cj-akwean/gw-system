<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1.4cm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1a2b40; }
    .brand-band { width: 100%; background-color: #0f4c81; color: #ffffff; padding: 10px 12px; }
    .brand-band h1 { font-size: 17px; font-weight: bold; margin: 0; letter-spacing: 1px; }
    .brand-band .sub { font-size: 9px; margin: 2px 0 0; color: #cfe0f2; }
    .brand-band .doc { font-size: 10px; margin: 0; color: #ffffff; text-align: right; }
    .brand-band .status { font-size: 9px; font-weight: bold; margin: 4px 0 0; text-align: right; padding: 3px 8px; border-radius: 3px; display: inline-block; }
    .status.paid { background-color: #0a7d4d; color: #ffffff; }
    .status.unpaid { background-color: #b45309; color: #ffffff; }
    .status.other { background-color: #64748b; color: #ffffff; }
    .accent-rule { height: 3px; background-color: #f59e0b; margin-bottom: 12px; }
    .grid { display: inline-block; width: 49%; vertical-align: top; box-sizing: border-box; }
    .grid.left { float: left; }
    .grid.right { float: right; }
    .panel { border: 1px solid #d7e1ec; padding: 6px 8px; margin-bottom: 12px; border-radius: 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #d7e1ec; padding: 4px 6px; font-size: 10px; }
    th { background: #e8f1fa; color: #0f4c81; text-align: left; }
    .right-cell { text-align: right; }
    .total-row td { font-weight: bold; background: #f2f7fc; }
    .paid-row td { font-weight: bold; color: #0a7d4d; background: #eaf6f0; }
    .note { font-size: 9px; color: #5a6b7d; margin-top: 8px; }
    .amount-paid { border: 1px solid #b9dcc9; background-color: #eaf6f0; padding: 6px 8px; margin-bottom: 12px; border-radius: 4px; }
    .amount-paid span { color: #0a7d4d; font-weight: bold; }
</style>
</head>
<body>

<table class="brand-band" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <h1>GUINOBATAN WATERWORKS</h1>
            <p class="sub">Guinobatan, Albay · Official Statement of Account</p>
        </td>
        <td align="right">
            <p class="doc">Invoice No. {{ $invoiceNumber }}</p>
            <span class="status {{ strtolower($status) === 'paid' ? 'paid' : (strtolower($status) === 'unpaid' ? 'unpaid' : 'other') }}">{{ $status }}</span>
        </td>
    </tr>
</table>
<div class="accent-rule"></div>

<div>
    <div class="grid left">
        <table>
            <tr><th>Account No.</th><td>{{ $accountNumber }}</td></tr>
            <tr><th>Meter No.</th><td>{{ $meterNumber }}</td></tr>
            <tr><th>Customer</th><td>{{ $customerName }}</td></tr>
            <tr><th>Payer</th><td>{{ $payer }}</td></tr>
            <tr><th>Address</th><td>{{ $addressLine }}</td></tr>
        </table>

        <table>
            <tr><th>Present</th><td>{{ $presentReading }}</td></tr>
            <tr><th>Previous</th><td>{{ $previousReading }}</td></tr>
            <tr><th>Consumption</th><td>{{ $cuMUsed }} cu.m.</td></tr>
            <tr><th>Rate</th><td>{{ $rateDisplay }}</td></tr>
        </table>
    </div>

    <div class="grid right">
        <table>
            <tr><th>Invoice No.</th><td>{{ $invoiceNumber }}</td></tr>
            <tr><th>Billing Period</th><td>{{ $billingPeriodStart }} — {{ $billingPeriodEnd }}</td></tr>
            <tr><th>Date Issued</th><td>{{ $issuedAt }}</td></tr>
            <tr><th>Due Date</th><td>{{ $dueDate }}</td></tr>
        </table>
    </div>
    <div style="clear:both;"></div>
</div>

@if ($paymentMethod !== null)
<div class="amount-paid">
    <span>PAID</span> on {{ $paidAt }} via {{ $paymentMethod }}@if ($paymentReference !== null && $paymentReference !== '') · Reference: {{ $paymentReference }}@endif
</div>
@endif

<table>
    <thead>
        <tr>
            <th>Description</th>
            <th class="right-cell">Amount (&#8369;)</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Current Charges</td>
            <td class="right-cell">{{ number_format($currentCharges, 2) }}</td>
        </tr>
        <tr>
            <td>Arrears</td>
            <td class="right-cell">{{ number_format($arrears, 2) }}</td>
        </tr>
        <tr>
            <td>{{ $penaltyLabel }}</td>
            <td class="right-cell">{{ number_format($penalty, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>Total Amount Due</td>
            <td class="right-cell">{{ number_format($total, 2) }}</td>
        </tr>
        @if ($paymentMethod !== null)
        <tr class="paid-row">
            <td>Amount Paid</td>
            <td class="right-cell">{{ number_format($amountPaid, 2) }}</td>
        </tr>
        @endif
    </tbody>
</table>

<p class="note">
    Please pay on or before the due date shown above to avoid penalty and possible service
    disconnection. This is an auto-generated statement; keep it for your records.
    &#8369; denotes Philippine peso.
</p>

</body>
</html>
