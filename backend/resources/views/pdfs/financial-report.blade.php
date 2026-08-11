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
    .accent-rule { height: 3px; background-color: #f59e0b; margin-bottom: 12px; }
    h2 { font-size: 12px; color: #0f4c81; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #d7e1ec; padding: 4px 6px; font-size: 10px; }
    th { background: #e8f1fa; color: #0f4c81; text-align: left; }
    .right-cell { text-align: right; }
    .total-row td { font-weight: bold; background: #f2f7fc; }
    .note { font-size: 9px; color: #5a6b7d; margin-top: 8px; }
</style>
</head>
<body>

<table class="brand-band" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <h1>GUINOBATAN WATERWORKS</h1>
            <p class="sub">Guinobatan, Albay · Financial Report</p>
        </td>
        <td align="right">
            <p class="doc">Generated {{ $generatedAt }}</p>
        </td>
    </tr>
</table>
<div class="accent-rule"></div>

<h2>Summary</h2>
<table>
    <tr>
        <th>Active customers</th>
        <td>{{ number_format($summary['active_connections']) }}</td>
        <th>Unpaid bills</th>
        <td>{{ number_format($summary['unpaid_bills']) }}</td>
    </tr>
    <tr>
        <th>Overdue bills</th>
        <td>{{ number_format($summary['overdue_bills']) }}</td>
        <th>Outstanding amount</th>
        <td class="right-cell">&#8369; {{ number_format($summary['outstanding_amount'], 2) }}</td>
    </tr>
    <tr>
        <th>Revenue this month</th>
        <td class="right-cell">&#8369; {{ number_format($summary['revenue_this_month'], 2) }}</td>
        <th></th>
        <td></td>
    </tr>
</table>

<h2>Revenue by Month</h2>
<table>
    <thead>
        <tr>
            <th>Month</th>
            <th class="right-cell">Revenue (&#8369;)</th>
        </tr>
    </thead>
    <tbody>
        @foreach (app(\App\Services\FinancialReportService::class)->monthlyRows($monthlyRevenue) as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="right-cell">{{ number_format($row['revenue'], 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="note">
    Auto-generated on {{ $generatedAt }} by the Guinobatan Waterworks System.
    &#8369; denotes Philippine peso.
</p>

</body>
</html>
