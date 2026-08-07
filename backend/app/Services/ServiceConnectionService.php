<?php

namespace App\Services;

use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\Barangay;
use App\Models\RateSchedule;
use App\Models\ServiceConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * Validates the CSV header row for the service-connection import.
     * Required columns: name, barangay, address. Everything else is optional,
     * so an exported master list (which carries pending_balance, created_at,
     * etc.) round-trips cleanly.
     *
     * @return array<int, string>
     */
    public function validateHeaders(array $row): array
    {
        $errors = [];
        $keys = array_map('strtolower', array_keys($row));

        foreach (['name', 'barangay', 'address'] as $required) {
            if (! in_array($required, $keys)) {
                $errors[] = "Missing required column: {$required}.";
            }
        }

        return $errors;
    }

    /**
     * Builds the preview/validation result rows for a CSV import, one entry
     * per data row. Blank account/meter numbers are auto-generated here with
     * in-file reservations so two blank rows never receive the same value and
     * a provided value already claimed by an earlier row is skipped. The
     * database unique constraints remain the final backstop at import time
     * (see createWithIdentifierBackstops()).
     *
     * @param  array<int, array<string, mixed>>  $csvRows
     * @return Collection<int, array<string, mixed>>
     */
    public function prepareImportRows(array $csvRows): Collection
    {
        $results = collect();
        $accounts = [];
        $meters = [];

        foreach ($csvRows as $index => $row) {
            $rowIndex = $index + 2;
            $row = $this->normalizeCells($row);

            $errors = [];
            $generated = ['account_number' => false, 'meter_number' => false];

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = 'Missing required column: name.';
            } elseif (mb_strlen($name) > 255) {
                $errors[] = 'Name cannot exceed 255 characters.';
            }

            $address = trim((string) ($row['address'] ?? ''));
            if ($address === '') {
                $errors[] = 'Missing required column: address.';
            } elseif (mb_strlen($address) > 255) {
                $errors[] = 'Address cannot exceed 255 characters.';
            }

            $barangayName = trim((string) ($row['barangay'] ?? ''));
            $barangay = null;
            if ($barangayName === '') {
                $errors[] = 'Missing required column: barangay.';
            } else {
                $barangay = Barangay::whereRaw('LOWER(name) = ?', [strtolower($barangayName)])->first();

                if (! $barangay) {
                    $errors[] = "Unknown barangay: {$barangayName}.";
                }
            }

            $providedAccount = trim((string) ($row['account_number'] ?? ''));
            $accountNumber = $providedAccount === ''
                ? $this->nextFreeIdentifier('account_number', 'GW-', $accounts)
                : $providedAccount;
            if ($providedAccount === '') {
                $generated['account_number'] = true;
            }
            $errors = [...$errors, ...$this->identifierInFileErrors(
                $providedAccount !== '', $accountNumber, 'account_number', $accounts, $rowIndex,
            )];

            $providedMeter = trim((string) ($row['meter_number'] ?? ''));
            $meterNumber = $providedMeter === ''
                ? $this->nextFreeIdentifier('meter_number', 'MTR-', $meters)
                : $providedMeter;
            if ($providedMeter === '') {
                $generated['meter_number'] = true;
            }

            $errors = [...$errors, ...$this->identifierInFileErrors(
                $providedMeter !== '', $meterNumber, 'meter_number', $meters, $rowIndex,
            )];

            $phone = trim((string) ($row['phone'] ?? ''));
            if ($phone !== '' && (mb_strlen($phone) > 20 || ! preg_match('/^[0-9+\-() ]+$/', $phone))) {
                $errors[] = 'Phone must be at most 20 characters and contain only digits, + - ( ) and spaces.';
            }

            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                $errors[] = 'Email must be at most 255 characters and a valid email address.';
            }

            $gender = strtolower(trim((string) ($row['gender'] ?? '')));
            if ($gender !== '' && ! in_array($gender, ['male', 'female'], true)) {
                $errors[] = 'Invalid gender: expected male or female.';
            }

            $civilStatus = strtolower(trim((string) ($row['civil_status'] ?? '')));
            if ($civilStatus !== '' && ! in_array($civilStatus, ['single', 'married', 'widowed', 'separated'], true)) {
                $errors[] = 'Invalid civil_status: expected single, married, widowed or separated.';
            }

            $birthdateParsed = $this->normalizeDate((string) ($row['birthdate'] ?? ''));
            if (trim((string) ($row['birthdate'] ?? '')) !== '' && $birthdateParsed === null) {
                $errors[] = 'Invalid birthdate.';
            } elseif ($birthdateParsed && strtotime($birthdateParsed) > strtotime('today')) {
                $errors[] = 'Birthdate cannot be in the future.';
            }

            $occupation = trim((string) ($row['occupation'] ?? ''));
            if ($occupation !== '' && mb_strlen($occupation) > 100) {
                $errors[] = 'Occupation cannot exceed 100 characters.';
            }

            $status = strtolower(trim((string) ($row['status'] ?? ''))) ?: 'active';
            if (! in_array($status, ['pending', 'active', 'inactive', 'disconnected'], true)) {
                $errors[] = 'Invalid status: expected pending, active, inactive or disconnected.';
            }

            $connectionDateDefaulted = false;
            $connectionDate = trim((string) ($row['connection_date'] ?? ''));
            if ($connectionDate === '') {
                $connectionDate = now()->format('Y-m-d');
                $connectionDateDefaulted = true;
            } else {
                $connectionDate = $this->normalizeDate($connectionDate);

                if ($connectionDate === null) {
                    $errors[] = 'Invalid connection_date.';
                }
            }

            $rateScheduleId = null;
            $rateScheduleName = trim((string) ($row['rate_schedule'] ?? ''));
            if ($rateScheduleName !== '') {
                $matches = RateSchedule::where('name', $rateScheduleName)->get();

                if ($matches->isEmpty()) {
                    $errors[] = "Unknown rate schedule: {$rateScheduleName}.";
                } elseif ($matches->count() > 1) {
                    $errors[] = "Multiple rate schedules match '{$rateScheduleName}'; specify an unambiguous schedule name.";
                } else {
                    $rateScheduleId = $matches->first()->id;
                }
            }

            $data = [
                'account_number' => $accountNumber,
                'meter_number' => $meterNumber,
                'registered_name' => $name,
                'barangay_id' => $barangay?->id,
                'address' => $address,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'gender' => $gender !== '' ? $gender : null,
                'birthdate' => $birthdateParsed,
                'civil_status' => $civilStatus !== '' ? $civilStatus : null,
                'occupation' => $occupation !== '' ? $occupation : null,
                'status' => $status,
                'connection_date' => $connectionDate,
                'rate_schedule_id' => $rateScheduleId,
            ];

            $notes = $errors !== []
                ? implode('; ', $errors)
                : $this->importWarnings($generated, $accountNumber, $meterNumber, $connectionDateDefaulted, $connectionDate);

            $results->push([
                'row' => $rowIndex,
                'valid' => empty($errors),
                'errors' => $errors,
                'notes' => $notes,
                'data' => $data,
                'original' => $row,
                'generated' => $generated,
                'account_number' => $accountNumber,
                'meter_number' => $meterNumber,
                'name' => $name,
                'barangay' => $barangay?->name ?? $barangayName,
                'status' => $status,
                'connection_date' => $connectionDate,
            ]);
        }

        return $results;
    }

    /**
     * Saves a connection with a bounded roll-forward retry on a Postgres
     * unique-violation, so a preview-stage identifier that went stale under a
     * concurrent insert (or collided across two simultaneous imports) rolls
     * forward instead of failing loudly. Mirrors the "unique constraint catches
     * any race" stance of BillingService::generateInvoiceNumber().
     *
     * Each save runs inside its own nested transaction (a SAVEPOINT on
     * Postgres). Without it a `23505` aborts the whole outer transaction, so
     * the follow-up `nextIdentifier()` lookup would itself throw `25P02` and
     * the retry could never succeed.
     *
     * Only machine-format identifiers (GW-/MTR- + digits) are regenerated, and
     * only when the value still looks generated (the blank-identifier value
     * auto-assigned at preview). A non-matching value, or three failed save
     * attempts, surfaces as a normal validation error instead of leaking a raw
     * 23505 to the caller.
     */
    public function createWithIdentifierBackstops(array $data): ServiceConnection
    {
        $attempts = 0;

        while (true) {
            try {
                $record = new ServiceConnection($data);

                DB::transaction(function () use ($record): void {
                    $record->save();
                });

                return $record;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }

                $column = $this->collidedColumn($e);

                if ($attempts >= 2 || $column === null || ! $this->isGenerated((string) ($data[$column] ?? ''))) {
                    throw ValidationException::withMessages([
                        $column ?? 'account_number' => 'This value is already in use. Please enter a different account or meter number.',
                    ]);
                }

                $data[$column] = $this->nextIdentifier(
                    $column,
                    $column === 'account_number' ? 'GW-' : 'MTR-',
                );
            }

            $attempts++;
        }
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

    /**
     * Next unused identifier for the given column/prefix that also skips every
     * value already claimed by the current file (totals provided or generated),
     * so two blank rows can never receive the same number during a single
     * import.
     *
     * @param  array<string, int>  $claimed  value => first row index
     */
    private function nextFreeIdentifier(string $column, string $prefix, array &$claimed): string
    {
        $base = $this->nextIdentifier($column, $prefix);
        $suffix = (int) substr($base, strlen($prefix));

        do {
            $candidate = $prefix.str_pad((string) $suffix, 5, '0', STR_PAD_LEFT);
            $suffix++;
        } while (isset($claimed[$candidate]));

        $claimed[$candidate] = 0;

        return $candidate;
    }

    /**
     * Registers an identifier in the file's claimed map (for the generated
     * path this is a no-op — nextFreeIdentifier already claimed it) and
     * returns per-row errors for provided values: duplicates inside the file
     * and values already present in the database.
     *
     * @param  array<string, int>  $claimed
     * @return array<int, string>
     */
    private function identifierInFileErrors(
        bool $isProvided,
        string $effective,
        string $column,
        array &$claimed,
        int $rowIndex,
    ): array {
        $errors = [];

        if ($isProvided && isset($claimed[$effective])) {
            $errors[] = "{$column} {$effective} already appears in this file (row {$claimed[$effective]}).";
        } else {
            $claimed[$effective] = $rowIndex;
        }

        if (ServiceConnection::where($column, $effective)->exists()) {
            $errors[] = "{$column} {$effective} already exists.";
        }

        return $errors;
    }

    /**
     * Notes surfaced on valid rows so the preview reads honestly: values the
     * import will generate or default rather than read from the CSV.
     */
    private function importWarnings(
        array $generated,
        string $accountNumber,
        string $meterNumber,
        bool $connectionDateDefaulted,
        ?string $connectionDate,
    ): string {
        $warnings = [];

        if ($generated['account_number']) {
            $warnings[] = "Account number auto-generated: {$accountNumber}";
        }

        if ($generated['meter_number']) {
            $warnings[] = "Meter number auto-generated: {$meterNumber}";
        }

        if ($connectionDateDefaulted) {
            $warnings[] = "Connection date defaulted to today ({$connectionDate})";
        }

        return implode('; ', $warnings);
    }

    /**
     * Maps a unique-violation exception to the column whose DB constraint
     * fired, read from the Postgres `DETAIL:  Key (column)=…` line (the
     * broader message mentions every column in the INSERT, so membership
     * matching alone is ambiguous). Returns null when the constraint cannot be
     * identified, in which case no roll-forward is attempted.
     */
    private function collidedColumn(QueryException $e): ?string
    {
        if (preg_match('/Key \((\w+)\)=/', $e->getMessage(), $matches)) {
            $column = $matches[1];

            return in_array($column, ['account_number', 'meter_number'], true) ? $column : null;
        }

        return null;
    }

    /**
     * True when the value matches the auto-generated identifier format, meaning
     * it is safe to roll forward (either a value we generated ourselves on an
     * imported row or an earlier retry). Values not in this format are never
     * treated as replaceable.
     */
    private function isGenerated(string $value): bool
    {
        return (bool) preg_match('/^(?:GW|MTR)-\d+$/', trim($value));
    }

    private function normalizeCells(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[$key] = is_string($value) ? trim($value) : $value;
        }

        return $out;
    }

    private function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }
}
