<?php

namespace App\Services;

use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\ServiceConnection;

class ServiceConnectionService
{
    /**
     * Next unused identifier for the given column and prefix, derived from
     * the highest numeric suffix currently in use (e.g. "GW-00042" + 1).
     * Rows whose values don't match the prefix are ignored, so office-issued
     * numbers of any format never block the sequence.
     */
    public function nextIdentifier(string $column, string $prefix, int $pad = 5): string
    {
        $max = (int) ServiceConnection::query()
            ->where($column, '~', '^'.preg_quote($prefix, '/').'\d+$')
            ->pluck($column)
            ->map(fn (string $value): int => (int) substr($value, strlen($prefix)))
            ->max();

        return $prefix.str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT);
    }

    /**
     * Auto-suggested identifiers for a new connection: account "GW-00001"
     * style and meter "MTR-00001" style, both guaranteed unused at call time.
     *
     * @return array{account_number: string, meter_number: string}
     */
    public function suggestIdentifiers(): array
    {
        return [
            'account_number' => $this->nextIdentifier('account_number', 'GW-'),
            'meter_number' => $this->nextIdentifier('meter_number', 'MTR-'),
        ];
    }

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

        SendConnectionIdentifierChangedEmail::dispatch(
            $connection,
            $connection->id,
            array_filter($oldIdentifiers),
            $recipients,
        );

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
