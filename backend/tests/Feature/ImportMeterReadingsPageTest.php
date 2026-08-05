<?php

namespace Tests\Feature;

use App\Filament\Resources\MeterReadingResource\Pages\ImportMeterReadings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use Tests\TestCase;

class ImportMeterReadingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.tmp-for-tests' => [
            'driver' => 'local',
            'root' => storage_path('framework/livewire-tmp'),
        ]]);
    }

    public function test_import_form_file_upload_persists_across_livewire_requests(): void
    {
        $component = Livewire::test(ImportMeterReadings::class);

        $component
            ->assertSee('fi-fo-file-upload')
            ->set('validCount', 1)
            ->assertSee('fi-fo-file-upload');
    }

    public function test_upload_completes_with_filament_dotted_path_and_auto_previews(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $file = UploadedFile::fake()->createWithContent(
            'meter.csv',
            "account_number,present_reading,reading_date\nGW-99999,10.00,2026-07-30\n"
        );

        $name = 'data.csvFile.'.Str::uuid();

        $component = Livewire::actingAs($user, 'admin')->test(ImportMeterReadings::class);

        $component->call('_startUpload', $name, [
            ['name' => $file->name, 'size' => $file->getSize(), 'type' => $file->getMimeType()],
        ], false);

        $fileHashes = (new FileUploadController)->validateAndStore([$file], FileUploadConfiguration::disk());

        $component->call('_finishUpload', $name, $fileHashes, false);

        $component
            ->assertOk()
            ->assertSet('hasPreview', true)
            ->assertSet('invalidCount', 1);
    }
}
