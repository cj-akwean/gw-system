<?php

namespace Tests\Feature;

use App\Filament\Resources\MeterReadingResource\Pages\ImportMeterReadings;
use Livewire\Livewire;
use Tests\TestCase;

class ImportMeterReadingsPageTest extends TestCase
{
    public function test_import_form_file_upload_persists_across_livewire_requests(): void
    {
        $component = Livewire::test(ImportMeterReadings::class);

        $component
            ->assertSee('fi-fo-file-upload')
            ->set('validCount', 1)
            ->assertSee('fi-fo-file-upload');
    }
}
