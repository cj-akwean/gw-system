<x-mail::message>
# Your water account details have been updated

Details for **{{ $serviceConnection->registered_name }}** were updated on **{{ now()->format('M d, Y') }}**.

<x-mail::table>
| Field | Previous | Now |
|---|---|---|
@if(array_key_exists('account_number', $oldIdentifiers) && $oldIdentifiers['account_number'] !== $serviceConnection->account_number)
| Account number | {{ $oldIdentifiers['account_number'] }} | {{ $serviceConnection->account_number }} |
@endif
@if(array_key_exists('meter_number', $oldIdentifiers) && $oldIdentifiers['meter_number'] !== $serviceConnection->meter_number)
| Meter number | {{ $oldIdentifiers['meter_number'] }} | {{ $serviceConnection->meter_number }} |
@endif
</x-mail::table>

If you have a copy of an older bill, its account and meter numbers may no longer match — use the new ones for future reference.

Your portal access is unaffected.

Thanks,<br>
{{ config('mail.from.name') }}
</x-mail::message>