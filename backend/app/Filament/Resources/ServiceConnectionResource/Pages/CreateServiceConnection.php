<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use App\Services\ServiceConnectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

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
     * Delegates to the shared ServiceConnectionService so the create form and the
     * CSV import path run through one collided-identifier roll-forward
     * implementation. The service keeps the "only machine-format identifiers
     * are regenerated" rule and the SAVEPOINT-per-save mechanics that allow a
     * `23505` retry to actually succeed.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ServiceConnectionService::class)->createWithIdentifierBackstops($data);
    }
}
