<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use App\Services\ServiceConnectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateServiceConnection extends CreateRecord
{
    protected static string $resource = ServiceConnectionResource::class;

    protected function fillForm(): void
    {
        parent::fillForm();

        $identifiers = app(ServiceConnectionService::class)->suggestIdentifiers();

        $this->form->fillPartially(
            $identifiers,
            ['account_number', 'meter_number'],
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Bounded retry on a Postgres unique-violation so a pre-filled suggestion
     * that went stale under a concurrent create rolls forward instead of
     * failing loudly (mirrors the "unique constraint catches any race" stance
     * of BillingService::generateInvoiceNumber()).
     *
     * Each save runs inside its own nested transaction (a SAVEPOINT on
     * Postgres). Without it a `23505` aborts the whole outer transaction, so the
     * follow-up `nextIdentifier()` lookup would itself throw `25P02` and the
     * retry could never succeed.
     *
     * Only the column whose constraint fired is regenerated, and only when it
     * still holds a machine-generated value (the untouched suggestion, or a
     * value rolled forward on an earlier attempt). Values an admin typed by
     * hand are never overwritten: a collision on one of those, or three failed
     * save attempts, surfaces as a normal form error instead of leaking a raw
     * 23505 to the user.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ServiceConnectionService::class);
        $attempts = 0;

        while (true) {
            try {
                $record = new ($this->getModel())($data);

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

                $data[$column] = $service->nextIdentifier(
                    $column,
                    $column === 'account_number' ? 'GW-' : 'MTR-',
                );
            }

            $attempts++;
        }
    }

    /**
     * Maps a unique-violation exception to the column whose DB constraint
     * fired, read from the Postgres `DETAIL:  Key (column)=...` line (the
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
     * it is safe to roll forward (either the untouched suggestion or a value we
     * generated ourselves on an earlier retry). Human-typed values are never
     * treated as replaceable.
     */
    private function isGenerated(string $value): bool
    {
        return (bool) preg_match('/^(?:GW|MTR)-\d+$/', trim($value));
    }
}