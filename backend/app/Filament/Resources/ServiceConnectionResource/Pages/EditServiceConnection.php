<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Filament\Resources\ServiceConnectionResource;
use App\Services\ServiceConnectionService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditServiceConnection extends EditRecord
{
    protected static string $resource = ServiceConnectionResource::class;

    /**
     * @var array<string, string>
     */
    protected array $previousIdentifiers = [];

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function beforeSave(): void
    {
        $this->previousIdentifiers = [
            'account_number' => $this->record->getOriginal('account_number'),
            'meter_number' => $this->record->getOriginal('meter_number'),
        ];
    }

    protected function afterSave(): void
    {
        $notified = app(ServiceConnectionService::class)->handleIdentifierChange($this->record, $this->previousIdentifiers);

        if ($notified > 0) {
            Notification::make()
                ->success()
                ->title('Account details updated')
                ->body("{$notified} linked portal user(s) notified by email.")
                ->send();
        }
    }
}
