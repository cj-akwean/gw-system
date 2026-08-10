<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>Your water account details have been updated</title>
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
    </style>
</head>
<body class="gw-font" style="margin: 0; padding: 0; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; min-width: 100%; width: 100%; background-color: #eef4f9;">

    <div class="gw-preheader">
        Your water account details have been updated
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

                    <!-- Body card -->
                    <tr>
                        <td>
                            <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%; background-color: #ffffff; border-radius: 12px; box-shadow: 0 2px 5px 0 rgb(15 76 129 / 8%), 0 1px 1px 0 rgb(0 0 0 / 4%);">
                                <tr>
                                    <td style="padding: 32px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 4px;">
                                                    <span style="color: #12294a; font-size: 22px; line-height: 28px; font-weight: 600;">
                                                        Your water account details have been updated
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 22px;">
                                                        Details for <strong style="color: #12294a;">{{ $serviceConnection->registered_name }}</strong> were updated on <strong style="color: #12294a;">{{ now()->format('M d, Y') }}</strong>.
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>

                                        @if (array_key_exists('account_number', $oldIdentifiers) && $oldIdentifiers['account_number'] !== $serviceConnection->account_number)
                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="20" style="height: 20px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 16px;">Field</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 16px;">Previous</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right" style="padding-bottom: 8px;">
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 16px;">Now</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">Account number</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">{{ $oldIdentifiers['account_number'] }}</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 600;">{{ $serviceConnection->account_number }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                        @endif

                                        @if (array_key_exists('meter_number', $oldIdentifiers) && $oldIdentifiers['meter_number'] !== $serviceConnection->meter_number)
                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="16" style="height: 16px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 500;">Meter number</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #5f7186; font-size: 14px; line-height: 16px;">{{ $oldIdentifiers['meter_number'] }}</span>
                                                </td>
                                                <td width="24" style="width: 24px;">&nbsp;</td>
                                                <td align="right">
                                                    <span style="color: #12294a; font-size: 14px; line-height: 16px; font-weight: 600;">{{ $serviceConnection->meter_number }}</span>
                                                </td>
                                            </tr>
                                        </table>
                                        @endif

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td height="24" style="height: 24px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="height: 1px; font-size: 1px; line-height: 1px; background-color: #e5ebf2;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td height="20" style="height: 20px; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <table width="100%" cellpadding="0" cellspacing="0" style="width: 100%;">
                                            <tr>
                                                <td style="padding-bottom: 6px;">
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 20px;">
                                                        If you have a copy of an older bill, its account and meter numbers may no longer match — use the new ones for future reference.
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <span style="color: #5f7186; font-size: 13px; line-height: 20px;">
                                                        Your portal access is unaffected.
                                                    </span>
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
