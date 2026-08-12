{{ config('mail.from.name') }}

Your password has been changed

The password for {{ $user->email }} was changed on {{ now()->format('M d, Y') }}.

If you made this change, no further action is needed. If you did NOT change
your password, contact us immediately - your account may have been compromised.

Questions? Contact us at {{ config('mail.from.address') }} or call (052) 000-0000.

Guinobatan Waterworks · Guinobatan, Albay
