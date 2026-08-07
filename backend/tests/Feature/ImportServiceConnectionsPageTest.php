<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceConnectionResource\Pages\ImportServiceConnections;
use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;
use Tests\TestCase;

class ImportServiceConnectionsPageTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function seedBarangay(string $name = 'Poblacion'): Barangay
    {
        return Barangay::factory()->create(['name' => $name]);
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('connections.csv', $contents);
    }

    /**
     * Simulates Filament's two-phase Livewire file upload (the same dance used
     * by ImportMeterReadingsPageTest) and returns the tested component.
     */
    private function upload(object $component, UploadedFile $file): object
    {
        $name = 'data.csvFile.'.Str::uuid();

        $component->call('_startUpload', $name, [
            ['name' => $file->name, 'size' => $file->getSize(), 'type' => $file->getMimeType()],
        ], false);

        $fileHashes = (new FileUploadController)->validateAndStore([$file], FileUploadConfiguration::disk());

        return $component->call('_finishUpload', $name, $fileHashes, false);
    }

    public function test_upload_auto_previews_generated_identifiers(): void
    {
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,barangay,address\nJuan,Poblacion,123 Main\nMaria,Poblacion,456 Other\n"
        ));

        $component
            ->assertOk()
            ->assertSet('hasPreview', true)
            ->assertSet('validCount', 2)
            ->assertSet('invalidCount', 0)
            ->assertSee('GW-00001')
            ->assertSee('GW-00002');
    }

    public function test_import_persists_connections_with_generated_identifiers(): void
    {
        Queue::fake();
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,barangay,address\nJuan,Poblacion,123 Main\nMaria,Poblacion,456 Other\n"
        ));

        $component
            ->call('import')
            ->assertSet('importedCount', 2)
            ->assertSet('hasPreview', false);

        $this->assertDatabaseHas('service_connections', [
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
            'registered_name' => 'Juan',
            'barangay_id' => Barangay::first()->id,
        ]);
        $this->assertDatabaseHas('service_connections', [
            'account_number' => 'GW-00002',
            'meter_number' => 'MTR-00002',
            'registered_name' => 'Maria',
        ]);

        Queue::assertNotPushed(SendConnectionIdentifierChangedEmail::class);
    }

    public function test_import_skips_invalid_rows(): void
    {
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,barangay,address\nJuan,Poblacion,123 Main\nNobody,Nowhere Ville,999 Lot\n"
        ));

        $component
            ->assertSet('validCount', 1)
            ->assertSet('invalidCount', 1)
            ->call('import')
            ->assertSet('importedCount', 1);

        $this->assertDatabaseHas('service_connections', ['registered_name' => 'Juan']);
        $this->assertDatabaseMissing('service_connections', ['registered_name' => 'Nobody']);
    }

    public function test_duplicate_identifier_within_file_flags_row_as_invalid(): void
    {
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,barangay,address,account_number\nJuan,Poblacion,001,GW-7\nMaria,Poblacion,456 Other,GW-7\n"
        ));

        $component
            ->assertSet('validCount', 1)
            ->assertSet('invalidCount', 1);
    }

    public function test_missing_required_header_rejects_preview(): void
    {
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,address\nJuan,123 Main\n"
        ));

        $component->assertSet('hasPreview', false);
    }
}
