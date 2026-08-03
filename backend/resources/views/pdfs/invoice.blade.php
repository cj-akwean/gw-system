<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1.5cm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; }
    .header { text-align: center; margin-bottom: 14px; }
    .header h1 { font-size: 16px; font-weight: bold; margin: 0; letter-spacing: .5px; }
    .header p { font-size: 10px; margin: 2px 0 0; color: #444; }
    .grid { display: inline-block; width: 49%; vertical-align: top; box-sizing: border-box; }
    .grid.left { float: left; }
    .grid.right { float: right; }
    .panel { border: 1px solid #ccc; padding: 6px 8px; margin-bottom: 12px; }
    .badge { border: 1px solid #2563eb; padding: 6px 8px; margin-top: 8px; }
    .badge span { color: #2563eb; font-weight: bold; font-size: 10px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; font-size: 10px; }
    th { background: #f0f4ff; }
    .right-cell { text-align: right; }
    .total-row td { font-weight: bold; }
    .note { font-size: 9px; color: #555; margin-top: 8px; }
</style>
</head>
<body>

<div class="header">
    <h1>GUINOBATAN WATERWORKS</h1>
    <p>Guinobatan, Albay</p>
    <p>Official Statement of Account</p>
</div>

<div>
    <div class="grid left">
        <table>
            <tr><th>Account No.</th><td>{{ $accountNumber }}</td></tr>
            <tr><th>Meter No.</th><td>{{ $meterNumber }}</td></tr>
            <tr><th>Customer</th><td>{{ $customerName }}</td></tr>
            <tr><th>Address</th><td>{{ $addressLine }}</td></tr>
        </table>

        <table>
            <tr><th>Present</th><td>{{ $presentReading }}</td></tr>
            <tr><th>Previous</th><td>{{ $previousReading }}</td></tr>
            <tr><th>Consumption</th><td>{{ $cuMUsed }} cu.m.</td></tr>
            <tr><th>Rate</th><td>{{ $rateDisplay }}</td></tr>
        </table>

        <div class="badge"><span>Status:</span> {{ $status }}</div>
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
    </tbody>
</table>

<p class="note">
    Please pay on or before the due date shown above to avoid penalty and possible service
    disconnection. This is an auto-generated statement; keep it for your records.
    &#8369; denotes Philippine peso.
</p>

</body>
</html>
