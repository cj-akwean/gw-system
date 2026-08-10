<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Payment received — Invoice {{ $invoice->invoice_number }}</title>
    <style>
        html, body, td, a, span, div, p, table {
            border: 0 !important;
            margin: 0 !important;
            outline: 0 !important;
            text-decoration: none !important;
        }
        body, td {
            -webkit-font-smoothing: antialiased !important;
            -moz-osx-font-smoothing: grayscale !important;
        }
        .gw-font {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Ubuntu, sans-serif;
        }
        .gw-preheader {
            color: #eef4f9;
            display: none !important;
            font-size: 1px;
            line-height: 1px;
            max-height: 0;
            max-width: 0;
            mso-hide: all !important;
            opacity: 0;
            overflow: hidden;
            visibility: hidden;
        }
        .gw-rule {
            height: 1px;
            font-size: 1px;
            line-height: 1px;
            background-color: #e5ebf2;
        }
    </style>
</head>
<body class="gw-font" style="margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; min-width: 100%; width: 100%; background-color: #eef4f9;">

    <div class="gw-preheader">
        Payment received — ₱{{ number_format($payment->amount, 2) }} for invoice {{ $invoice->invoice_number }}
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #eef4f9;">
        <tr>
            <td align="center" style="padding: 32px 16px 24px;">

                <table cellpadding="0" cellspacing="0" style="width: 560px; min-width: 560px; max-width: 560px;">

                    <!-- Header / brand -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                                <tr>
                                    <td width="44" valign="middle" style="width: 44px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" valign="middle" width="40" height="40" style="width: 40px; height: 40px; background-color: #0f4c81; border-radius: 100%;">
                                                    <span style="color: #ffffff; font-size: 15px; font-weight: 700; line-height: 40px;">GW</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="12" style="width: 12px;">&nbsp;</td>
                                    <td valign="middle">
                                        <span style="color: #12294a; font-size: 17px; font-weight: 600;">
                                            Guinobatan Waterworks<span style="color: #f59e0b;">.</span>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Card 1 : amount + details -->
                    <tr>
                        <td>
                            <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #ffffff; border-radius: 12px; box-shadow: 0 2px 5px 0 rgb(15 76 129 / 8%), 0 1px 1px 0 rgb(0 0 0 / 4%);">
                                <tr>
                                    <td style="padding: 32px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 2px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 20px; font-weight: 500;">
                                                        Payment received
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 2px;">
                                                    <span style="color: #12294a; font-size: 36px; line-height: 42px; font-weight: 600;">
                                                        ₱{{ number_format($payment->amount, 2) }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 24px; font-weight: 500;">
                                                        Paid {{ $payment->paid_at?->format('F j, Y') ?? now()->format('F j, Y') }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="20" style="height: 20px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td class="gw-rule" style="height: 1px; font-size: 1px; line-height: 1px; background-color: #e5ebf2;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td height="20" style="height: 20px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td>
                                                    <table cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td width="28" height="28" align="center" valign="middle" style="width: 28px; height: 28px; background-color: #e8f1fa; border-radius: 6px;">
                                                                <span style="color: #0f4c81; font-size: 9px; font-weight: 700; letter-spacing: .5px;">PDF</span>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td width="10" style="width: 10px;">&nbsp;</td>
                                                <td valign="middle">
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 18px;">
                                                        Your itemized invoice PDF is attached to this email.
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="24" style="height: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Invoice number</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $invoice->invoice_number }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Customer</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $invoice->serviceConnection?->registered_name ?? '—' }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Account No.</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $invoice->serviceConnection?->account_number ?? '—' }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Meter No.</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $invoice->serviceConnection?->meter_number ?? '—' }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Billing period</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">
                                                        {{ $invoice->billing_period_start?->format('M d, Y') ?? '—' }} – {{ $invoice->billing_period_end?->format('M d, Y') ?? '—' }}
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Payment method</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $paymentMethodLabel }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Payer</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">
                                                        @if ($payment->payer_name !== null)
                                                            {{ $payment->payer_name }}@if ($payment->payer_email !== null) · {{ $payment->payer_email }}@endif@if ($payment->payer_phone !== null) · {{ $payment->payer_phone }}@endif
                                                        @else
                                                            —
                                                        @endif
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">Reference</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">{{ $payment->reference ?? $payment->paymongo_reference ?? '—' }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td height="16" style="height: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                    </tr>

                    <!-- Card 2 : breakdown -->
                    <tr>
                        <td>
                            <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #ffffff; border-radius: 12px; box-shadow: 0 2px 5px 0 rgb(15 76 129 / 8%), 0 1px 1px 0 rgb(0 0 0 / 4%);">
                                <tr>
                                    <td style="padding: 32px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 20px;">
                                                    <span style="color: #12294a; font-size: 16px; line-height: 20px; font-weight: 600;">
                                                        Payment details
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 12px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">Current charges</span>
                                                </td>
                                                <td width="16" style="width: 16px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 12px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">₱{{ number_format($invoice->base_amount, 2) }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 12px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">Arrears</span>
                                                </td>
                                                <td width="16" style="width: 16px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 12px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">₱{{ number_format($invoice->previous_balance, 2) }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 20px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">Penalty</span>
                                                </td>
                                                <td width="16" style="width: 16px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 20px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">₱{{ number_format($invoice->penalty_amount, 2) }}</span>
                                                </td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="16" style="height: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td class="gw-rule" style="height: 1px; font-size: 1px; line-height: 1px; background-color: #e5ebf2;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td height="16" style="height: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 16px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 600;">Total</span>
                                                </td>
                                                <td width="16" style="width: 16px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 16px;">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 600;">₱{{ number_format($invoice->total_amount, 2) }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #0f4c81; font-size: 15px; line-height: 18px; font-weight: 700;">Amount paid</span>
                                                </td>
                                                <td width="16" style="width: 16px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #0f4c81; font-size: 15px; line-height: 18px; font-weight: 700;">₱{{ number_format($payment->amount, 2) }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td height="28" style="height: 28px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center">
                            <table cellpadding="0" cellspacing="0" style="width: 100%;">
                                <tr>
                                    <td align="center">
                                        <span style="color: #5f7186; font-size: 12px; line-height: 20px;">
                                            Questions? Contact us at
                                            <a href="mailto:{{ config('mail.from.address') }}" style="color: #0f4c81 !important; font-weight: 600; white-space: nowrap;">{{ config('mail.from.address') }}</a>
                                            or call <a href="tel:0520000000" style="color: #0f4c81 !important; font-weight: 600; white-space: nowrap;">(052) 000-0000</a>.
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td height="6" style="height: 6px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <span style="color: #93a4b8; font-size: 11px; line-height: 18px;">
                                            Guinobatan Waterworks · Guinobatan, Albay
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>
