<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use App\Services\ReadingService;
use Filament\Resources\Pages\EditRecord;

class EditMeterReading extends EditRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $service = app(ReadingService::class);
        $previous = (float) ($data['previous_reading'] ?? $service->getPreviousReading((int) $data['service_connection_id']));
        $present = (float) $data['present_reading'];

        $data['previous_reading'] = $previous;
        $data['cu_m_used'] = $service->computeUsage($present, $previous);

        unset($data['entered_by'], $data['method']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
