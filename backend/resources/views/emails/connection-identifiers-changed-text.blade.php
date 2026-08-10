{{ config('mail.from.name') }}

Your water account details have been updated

Details for {{ $serviceConnection->registered_name }} were updated on {{ now()->format('M d, Y') }}.

@if (array_key_exists('account_number', $oldIdentifiers) && $oldIdentifiers['account_number'] !== $serviceConnection->account_number)
Account number:  {{ $oldIdentifiers['account_number'] }} → {{ $serviceConnection->account_number }}
@endif
@if (array_key_exists('meter_number', $oldIdentifiers) && $oldIdentifiers['meter_number'] !== $serviceConnection->meter_number)
Meter number:    {{ $oldIdentifiers['meter_number'] }} → {{ $serviceConnection->meter_number }}
@endif

If you have a copy of an older bill, its account and meter numbers may no longer match — use the new ones for future reference.

Your portal access is unaffected.

Questions? Contact us at {{ config('mail.from.address') }} or call (052) 000-0000.

Guinobatan Waterworks · Guinobatan, Albay
