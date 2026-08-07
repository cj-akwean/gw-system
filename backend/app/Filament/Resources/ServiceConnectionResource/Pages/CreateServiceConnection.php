<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use App\Services\ServiceConnectionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

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
     */
    protected function handleRecordCreation(array $data): Model
    {
        $service = app(ServiceConnectionService::class);
        $attempts = 0;

        do {
            try {
                $record = new ($this->getModel())($data);

                $record->save();

                return $record;
            } catch (QueryException $e) {
                if ($attempts >= 2 || $e->getCode() !== '23505') {
                    throw $e;
                }

                $data['account_number'] = $service->nextIdentifier('account_number', 'GW-');
                $data['meter_number'] = $service->nextIdentifier('meter_number', 'MTR-');
            }

            $attempts++;
        } while (true);
    }
}