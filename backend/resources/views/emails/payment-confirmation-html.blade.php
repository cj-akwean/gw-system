<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="format-detection" content="telephone=no">
    <title>Payment received — Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body, table, td, a, span, p {
            border: 0;
            margin: 0;
            padding: 0;
        }
        body {
            background-color: #eef4f9;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        .gw-preheader {
            display: none !important;
            mso-hide: all !important;
            font-size: 1px;
            line-height: 1px;
            color: #eef4f9;
            max-height: 0;
            max-width: 0;
            opacity: 0;
            overflow: hidden;
            visibility: hidden;
        }
        .gw-container {
            width: 600px;
            max-width: 600px;
        }
        .gw-card {
            background-color: #ffffff;
            border: 1px solid #dbe4ee;
            border-radius: 12px;
        }
        .gw-rule {
            height: 1px;
            font-size: 1px;
            line-height: 1px;
            background-color: #e5ebf2;
        }
        a {
            text-decoration: none;
        }
        @media screen and (max-width: 600px) {
            .gw-container {
                width: 100% !important;
                max-width: 100% !important;
            }
            .gw-pad {
                padding-left: 14px !important;
                padding-right: 14px !important;
            }
            .gw-card-pad {
                padding: 22px 20px !important;
            }
            .gw-h1 {
                font-size: 30px !important;
                line-height: 36px !important;
            }
            .gw-stack td {
                display: block !important;
                width: 100% !important;
                text-align: left !important;
                padding-bottom: 6px !important;
            }
            .gw-stack .gw-gap {
                display: none !important;
                height: 0 !important;
                font-size: 0 !important;
                line-height: 0 !important;
            }
            .gw-stack .gw-right {
                padding-bottom: 14px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; min-width: 100%; background-color: #eef4f9; font-family: Arial, Helvetica, sans-serif;">

    <div class="gw-preheader">
        Payment received — &#8369;{{ number_format($payment->amount, 2) }} for invoice {{ $invoice->invoice_number }}
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; background-color: #eef4f9;">
        <tr>
            <td align="center" class="gw-pad" style="padding: 32px 16px 24px;">

                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="gw-container" style="width: 600px; max-width: 600px;">

                    <!-- Header / brand -->
                    <tr>
                        <td style="padding-bottom: 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                <tr>
                                    <td width="44" valign="middle" style="width: 44px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td align="center" valign="middle" width="40" height="40" style="width: 40px; height: 40px; background-color: #0f4c81; border-radius: 100%; font-size: 15px; font-weight: bold; color: #ffffff; line-height: 40px;">GW</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td width="12" style="width: 12px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                    <td valign="middle" style="font-size: 17px; font-weight: bold; color: #12294a;">
                                        Guinobatan Waterworks<span style="color: #2563eb;">.</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Card 1 : amount + invoice details -->
                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="gw-card" style="width: 100%; background-color: #ffffff; border: 1px solid #dbe4ee; border-radius: 12px;">
                                <tr>
                                    <td class="gw-card-pad" style="padding: 32px;">

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 2px; font-size: 14px; line-height: 20px; color: #5f7186;">Payment received</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 2px; font-size: 36px; line-height: 42px; color: #12294a; font-weight: bold;" class="gw-h1">&#8369;{{ number_format($payment->amount, 2) }}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px; line-height: 24px; color: #5f7186;">Paid {{ $payment->paid_at?->format('F j, Y') ?? now()->format('F j, Y') }}</td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
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

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                            <tr>
                                                <td width="28" height="28" align="center" valign="middle" style="width: 28px; height: 28px; background-color: #e8f1fa; border-radius: 6px; font-size: 9px; font-weight: bold; color: #0f4c81; letter-spacing: 0.5px;">PDF</td>
                                                <td width="10" style="width: 10px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td valign="middle" style="font-size: 13px; line-height: 18px; color: #5f7186;">Your itemized invoice PDF is attached to this email.</td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                            <tr>
                                                <td height="24" style="height: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="gw-stack" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Invoice number</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $invoice->invoice_number }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Customer</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $invoice->serviceConnection?->registered_name ?? '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Account No.</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $invoice->serviceConnection?->account_number ?? '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Meter No.</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $invoice->serviceConnection?->meter_number ?? '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Billing period</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $invoice->billing_period_start?->format('M d, Y') ?? '—' }} – {{ $invoice->billing_period_end?->format('M d, Y') ?? '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Payment method</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $paymentMethodLabel }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #5f7186;">Payer</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 8px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">@if ($payment->payer_name !== null){{ $payment->payer_name }}@if ($payment->payer_email !== null) · {{ $payment->payer_email }}@endif@if ($payment->payer_phone !== null) · {{ $payment->payer_phone }}@endif@else—@endif</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 14px; line-height: 16px; color: #5f7186;">Reference</td>
                                                <td width="24" class="gw-gap" style="width: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">{{ $payment->reference ?? $payment->paymongo_reference ?? '—' }}</td>
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
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="gw-card" style="width: 100%; background-color: #ffffff; border: 1px solid #dbe4ee; border-radius: 12px;">
                                <tr>
                                    <td class="gw-card-pad" style="padding: 32px;">

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 20px; font-size: 16px; line-height: 20px; color: #12294a; font-weight: bold;">Payment details</td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="gw-stack" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 12px; font-size: 14px; line-height: 16px; color: #12294a;">Current charges</td>
                                                <td width="16" class="gw-gap" style="width: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 12px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">&#8369;{{ number_format($invoice->base_amount, 2) }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 12px; font-size: 14px; line-height: 16px; color: #12294a;">Arrears</td>
                                                <td width="16" class="gw-gap" style="width: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 12px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">&#8369;{{ number_format($invoice->previous_balance, 2) }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 20px; font-size: 14px; line-height: 16px; color: #12294a;">Penalty</td>
                                                <td width="16" class="gw-gap" style="width: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 20px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">&#8369;{{ number_format($invoice->penalty_amount, 2) }}</td>
                                            </tr>
                                        </table>

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
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

                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="gw-stack" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 16px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">Total</td>
                                                <td width="16" class="gw-gap" style="width: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="padding-bottom: 16px; font-size: 14px; line-height: 16px; color: #12294a; font-weight: bold;">&#8369;{{ number_format($invoice->total_amount, 2) }}</td>
                                            </tr>
                                            <tr>
                                                <td style="font-size: 15px; line-height: 18px; color: #2563eb; font-weight: bold;">Amount paid</td>
                                                <td width="16" class="gw-gap" style="width: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                                <td align="right" class="gw-right" style="font-size: 15px; line-height: 18px; color: #2563eb; font-weight: bold;">&#8369;{{ number_format($payment->amount, 2) }}</td>
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
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
                                <tr>
                                    <td align="center" style="padding-bottom: 16px; font-size: 12px; line-height: 20px; color: #5f7186;">
                                        Questions about your bill? Contact us at
                                        <a href="mailto:{{ config('mail.from.address') }}" style="color: #2563eb; font-weight: bold; text-decoration: underline; white-space: nowrap;">{{ config('mail.from.address') }}</a>
                                        or call <a href="tel:0520000000" style="color: #2563eb; font-weight: bold; text-decoration: underline; white-space: nowrap;">(052) 000-0000</a>.
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td align="center">
                                                    <!--[if mso]>
                                                    <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="mailto:{{ config('mail.from.address') }}" style="height:44px;v-text-anchor:middle;width:160px;" arcsize="14%" stroke="f" fillcolor="#2563eb">
                                                        <w:anchorlock/>
                                                        <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">Contact us</center>
                                                    </v:roundrect>
                                                    <![endif]-->
                                                    <!--[if !mso]><!-->
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                        <tr>
                                                            <td align="center" bgcolor="#2563eb" style="border-radius: 6px; background-color: #2563eb;">
                                                                <a href="mailto:{{ config('mail.from.address') }}" style="display: inline-block; padding: 13px 32px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; line-height: 18px; color: #ffffff; text-decoration: none;">Contact us</a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    <!--<![endif]-->
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td height="16" style="height: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                </tr>
                                <tr>
                                    <td align="center" style="font-size: 11px; line-height: 18px; color: #93a4b8;">
                                        Guinobatan Waterworks · Guinobatan, Albay<br>
                                        You are receiving this email because you are linked to this water account.
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
