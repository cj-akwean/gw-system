<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use App\Services\ServiceConnectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateServiceConnection extends CreateRecord
{
    protected static string $resource = ServiceConnectionResource::class;

    /**
     * Last identifiers auto-suggested by the form, so a collision on save can
     * tell "the caller's own generated value" apart from "a value the admin
     * typed". Only suggested values are eligible for roll-forward; anything
     * typed verbatim surfaces as a validation error instead of being silently
     * renumbered.
     *
     * @var array{account_number: string, meter_number: string}
     */
    public array $suggestedIdentifiers = [
        'account_number' => '',
        'meter_number' => '',
    ];

    protected function fillForm(): void
    {
        parent::fillForm();

        $this->suggestedIdentifiers = app(ServiceConnectionService::class)->suggestIdentifiers();

        $this->form->fillPartially(
            $this->suggestedIdentifiers,
            ['account_number', 'meter_number'],
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    /**
     * Delegates to the shared ServiceConnectionService so the create form and the
     * CSV import path run through one collided-identifier roll-forward
     * implementation. The service keeps the "only auto-generated identifiers
     * are regenerated" rule and the SAVEPOINT-per-save mechanics that allow a
     * `23505` retry to actually succeed.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ServiceConnectionService::class)->createWithIdentifierBackstops(
            $data,
            [
                'account_number' => ($this->suggestedIdentifiers['account_number'] ?? null) === ($data['account_number'] ?? null),
                'meter_number' => ($this->suggestedIdentifiers['meter_number'] ?? null) === ($data['meter_number'] ?? null),
            ],
        );
    }
}
