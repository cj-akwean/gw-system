<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use App\Services\ReadingService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateMeterReading extends CreateRecord
{
    protected static string $resource = MeterReadingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $service = app(ReadingService::class);
        $previous = (float) ($data['previous_reading'] ?? $service->getPreviousReading((int) $data['service_connection_id']));
        $present = (float) $data['present_reading'];

        $data['previous_reading'] = $previous;
        $data['cu_m_used'] = $service->computeUsage($present, $previous);
        $data['entered_by'] = Filament::auth()->id();
        $data['method'] = 'manual';

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
