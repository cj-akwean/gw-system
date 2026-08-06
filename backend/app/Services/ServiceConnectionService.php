<?php

namespace App\Services;

use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\ServiceConnection;

class ServiceConnectionService
{
    /**
     * After a connection is saved, compares its current account_number and
     * meter_number against the values held before the save and, if either
     * changed, emails every linked portal user the old → new identifiers.
     * Returns how many users were notified (0 when nothing changed or
     * nobody is linked).
     *
     * @param  array<string, string>  $previousIdentifiers  values before the save
     */
    public function handleIdentifierChange(ServiceConnection $connection, array $previousIdentifiers): int
    {
        $oldIdentifiers = [
            'account_number' => $connection->account_number !== $previousIdentifiers['account_number']
                ? $previousIdentifiers['account_number']
                : null,
            'meter_number' => $connection->meter_number !== $previousIdentifiers['meter_number']
                ? $previousIdentifiers['meter_number']
                : null,
        ];

        $changed = isset($oldIdentifiers['account_number']) || isset($oldIdentifiers['meter_number']);

        if (! $changed) {
            return 0;
        }

        $recipients = static::recipientsFor($connection);

        if ($recipients === []) {
            return 0;
        }

        SendConnectionIdentifierChangedEmail::dispatch($connection, array_filter($oldIdentifiers));

        return count($recipients);
    }

    /**
     * Distinct, lowercase, valid email addresses of portal users with an
     * active, non-unlinked link to the connection. Mirrors the recipient
     * logic used for payment confirmation emails.
     *
     * @return array<int, string>
     */
    public static function recipientsFor(ServiceConnection $connection): array
    {
        return $connection->connectionLinks()
            ->where('status', 'active')
            ->whereNull('unlinked_at')
            ->with('user:id,email')
            ->get()
            ->pluck('user.email')
            ->filter(function (mixed $email): bool {
                return is_string($email)
                    && trim($email) !== ''
                    && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
            })
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }
}
