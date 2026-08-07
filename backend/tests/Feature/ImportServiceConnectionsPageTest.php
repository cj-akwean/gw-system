<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceConnectionResource\Pages\ImportServiceConnections;
use App\Jobs\SendConnectionIdentifierChangedEmail;
use App\Models\Barangay;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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

    public function test_import_records_the_admin_who_imported(): void
    {
        $admin = $this->admin();
        $this->seedBarangay();

        $component = Livewire::actingAs($admin, 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv(
            "name,barangay,address\nJuan,Poblacion,123 Main\n"
        ));

        $component->call('import')->assertSet('importedCount', 1);

        $this->assertDatabaseHas('service_connections', [
            'account_number' => 'GW-00001',
            'imported_by' => $admin->id,
        ]);
    }

    public function test_mid_batch_failure_keeps_other_rows_is_logged_and_surfaces_rows(): void
    {
        Log::spy();
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        // Preview sees GW-00009 as free (it does not exist yet) so the row is
        // "valid"; the stale-non-generated collision surfaces only at save.
        $this->upload($component, $this->csv(
            "name,barangay,address,account_number,meter_number\nA,Poblacion,1,GW-00009,MTR-OFFICIAL-9\nB,Poblacion,2\nC,Poblacion,3\n"
        ));

        $component
            ->assertSet('validCount', 3)
            ->assertSet('invalidCount', 0);

        ServiceConnection::factory()->create([
            'account_number' => 'GW-00009',
            'meter_number' => 'MTR-00009',
            'barangay_id' => Barangay::first()->id,
            'registered_name' => 'Racer',
            'connection_date' => now(),
        ]);

        $component
            ->call('import')
            ->assertSet('importedCount', 2)
            ->assertSet('failedRows', [2]);

        // A failed and B/C still committed (SAVEPOINT semantics) —
        // without per-save SAVEPOINTs the mid-batch 23505 would abort the
        // transaction and nothing would import. B/C keep the identifiers
        // previewed against the (then-empty) DB.
        $this->assertDatabaseMissing('service_connections', ['registered_name' => 'A']);
        $this->assertDatabaseHas('service_connections', ['registered_name' => 'B', 'account_number' => 'GW-00001']);
        $this->assertDatabaseHas('service_connections', ['registered_name' => 'C', 'account_number' => 'GW-00002']);
        $this->assertDatabaseHas('service_connections', ['registered_name' => 'Racer', 'account_number' => 'GW-00009']);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('info');
    }

    public function test_imported_count_resets_when_a_new_file_is_previewed(): void
    {
        $this->seedBarangay();

        $component = Livewire::actingAs($this->admin(), 'admin')
            ->test(ImportServiceConnections::class);

        $this->upload($component, $this->csv("name,barangay,address\nJuan,Poblacion,123\n"));
        $component->call('import')->assertSet('importedCount', 1);

        $this->upload($component, $this->csv("name,barangay,address\nMaria,Poblacion,456\n"));

        $component
            ->assertSet('hasPreview', true)
            ->assertSet('importedCount', 0);
    }

    public function test_download_csv_sanitizes_formula_injection_cells(): void
    {
        $page = app(ImportServiceConnections::class);
        $page->hasPreview = true;
        $page->previewRows = collect([
            [
                'row' => 2,
                'valid' => true,
                'errors' => [],
                'notes' => '',
                'generated' => ['account_number' => false, 'meter_number' => false],
                'original' => ['name' => '=HYPERLINK("http://evil","Click")', 'address' => '123 Main'],
                'data' => [],
                'account_number' => 'GW-00001',
                'meter_number' => 'MTR-00001',
                'name' => 'x',
                'barangay' => 'b',
                'status' => 'active',
                'connection_date' => '2026-01-01',
            ],
        ]);

        $response = $page->downloadCsv();

        ob_start();
        $response->sendContent();
        $content = (string) ob_get_clean();

        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringNotContainsString(',=HYPERLINK', $content);
    }
}
