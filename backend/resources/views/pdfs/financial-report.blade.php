<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 1.2cm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1a2b40; }
    .brand-band { width: 100%; background-color: #0f4c81; color: #ffffff; padding: 10px 12px; }
    .brand-band h1 { font-size: 17px; font-weight: bold; margin: 0; letter-spacing: 1px; }
    .brand-band .sub { font-size: 9px; margin: 2px 0 0; color: #cfe0f2; }
    .brand-band .doc { font-size: 10px; margin: 0; color: #ffffff; text-align: right; }
    .accent-rule { height: 3px; background-color: #f59e0b; margin-bottom: 12px; }
    .meta { font-size: 10px; margin: 0 0 12px; color: #5a6b7d; }
    h2 { font-size: 12px; color: #0f4c81; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid #d7e1ec; padding: 4px 6px; font-size: 9px; }
    th { background: #e8f1fa; color: #0f4c81; text-align: left; }
    .right-cell { text-align: right; }
    .total-row td { font-weight: bold; background: #f2f7fc; }
    .sub { font-size: 8px; color: #5a6b7d; }
    .note { font-size: 8px; color: #5a6b7d; margin-top: 8px; }
    .danger { color: #b91c1c; }
</style>
</head>
<body>

<table class="brand-band" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <h1>GUINOBATAN WATERWORKS</h1>
            <p class="sub">Guinobatan, Albay · Accounting &amp; Financial Management</p>
        </td>
        <td align="right">
            <p class="doc">Generated {{ $generatedAt }}</p>
        </td>
    </tr>
</table>
<div class="accent-rule"></div>

<p class="meta">Reporting period: {{ $range['label'] }} &nbsp;·&nbsp; Receivables and AR aging as of {{ $generatedAt }}</p>

<h2>Receivables vs Collections</h2>
<table>
    <tr>
        <th>Total receivables (outstanding)</th>
        <td class="right-cell">&#8369; {{ number_format($summary['total_receivables'], 2) }}</td>
        <th>Total collections (period)</th>
        <td class="right-cell">&#8369; {{ number_format($summary['total_collections'], 2) }}</td>
    </tr>
</table>

<h2>Accounts Receivable Aging</h2>
<table>
    <thead>
        <tr>
            <th>Aging bucket</th>
            <th class="right-cell">Invoices</th>
            <th class="right-cell">Outstanding balance (&#8369;)</th>
            <th class="right-cell">Penalties accrued (&#8369;)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($aging as $bucket)
            <tr @class(['danger' => $bucket['key'] === 'overdue90'])>
                <td>{{ $bucket['label'] }}<div class="sub">{{ $bucket['range_label'] }}</div></td>
                <td class="right-cell">{{ number_format($bucket['count']) }}</td>
                <td class="right-cell">{{ number_format($bucket['amount'], 2) }}</td>
                <td class="right-cell">{{ number_format($bucket['penalty'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="total-row">
            <td>Total</td>
            <td class="right-cell">{{ number_format($aging->sum('count')) }}</td>
            <td class="right-cell">{{ number_format($aging->sum('amount'), 2) }}</td>
            <td class="right-cell">{{ number_format($aging->sum('penalty'), 2) }}</td>
        </tr>
    </tbody>
</table>

<h2>Cash vs Accrual Revenue — Statement of Income</h2>
<table>
    <thead>
        <tr>
            <th>Item</th>
            <th class="right-cell">Amount (&#8369;)</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Gross billed revenue (billed, accrual)</td><td class="right-cell">{{ number_format($income['gross_billed'], 2) }}</td></tr>
        <tr><td>Actual cash collections (cash, GCash, bank)</td><td class="right-cell">{{ number_format($income['cash_collections'], 2) }}</td></tr>
        <tr><td>Miscellaneous income (penalty charges)</td><td class="right-cell">{{ number_format($income['misc_income'], 2) }}</td></tr>
        <tr><td>Reconnection fees (not tracked)</td><td class="right-cell">{{ number_format($income['reconnection_fees'], 2) }}</td></tr>
        <tr><td>New connection setup fees (not tracked)</td><td class="right-cell">{{ number_format($income['setup_fees'], 2) }}</td></tr>
        <tr class="total-row"><td>Net operating income (gross billed + misc − collections)</td><td class="right-cell">{{ number_format($income['net_operating_income'], 2) }}</td></tr>
    </tbody>
</table>

<h2>Payment Breakdown &amp; Reconciliation</h2>
@if ($ledger->isEmpty())
    <p class="note">No payments recorded in the selected period.</p>
@else
    <table>
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Invoice #</th>
                <th>Account #</th>
                <th>Customer Name</th>
                <th>Payment Date</th>
                <th>Payment Method</th>
                <th class="right-cell">Amount (&#8369;)</th>
                <th>Reference #</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($ledger as $row)
                <tr>
                    <td>{{ $row['id'] }}</td>
                    <td>{{ $row['invoice_number'] }}</td>
                    <td>{{ $row['account_number'] }}</td>
                    <td>{{ $row['customer_name'] }}</td>
                    <td>{{ $row['paid_at'] }}</td>
                    <td>{{ $row['method'] }}</td>
                    <td class="right-cell">{{ number_format($row['amount'], 2) }}</td>
                    <td>{{ $row['reference'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<p class="note">
    Auto-generated on {{ $generatedAt }} by the Guinobatan Waterworks System.
    &#8369; denotes Philippine peso. Reconnection and setup fees are not tracked in the system yet and report as &#8369;0.
</p>

</body>
</html>
