<?php

namespace App\Filament\Resources\ServiceConnectionResource\Pages;

use App\Exports\Concerns\SanitizesCsvFields;
use App\Filament\Resources\ServiceConnectionResource;
use App\Imports\ServiceConnectionImport;
use App\Services\ServiceConnectionService;
use App\Support\AdminNotifier;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ImportServiceConnections extends Page
{
    use SanitizesCsvFields;

    /**
     * Serializes simultaneous imports so two admins cannot both auto-generate
     * the same identifier and burn each other's retry budget. Held for the
     * duration of the import transaction (auto-released on commit/rollback).
     */
    private const IMPORT_ADVISORY_LOCK_KEY = 6815473;

    protected static string $resource = ServiceConnectionResource::class;

    protected string $view = 'filament.pages.import-service-connections';

    public Collection $previewRows;

    public bool $hasPreview = false;

    public int $validCount = 0;

    public int $invalidCount = 0;

    public int $importedCount = 0;

    public array $failedRows = [];

    public array $data = [];

    public function mount(): void
    {
        $this->previewRows = collect();
        $this->failedRows = [];
        $this->cacheSchema('importForm', $this->getImportForm());
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('csvFile')
                    ->label('CSV file')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(2048),
            ]);
    }

    public function getImportForm(): Schema
    {
        return $this->importForm($this->makeSchema());
    }

    public function downloadCsv()
    {
        if (! $this->hasPreview) {
            return;
        }

        $columns = collect();

        foreach ($this->previewRows as $result) {
            foreach (array_keys($result['original']) as $key) {
                if ($columns->contains($key)) {
                    continue;
                }
                $columns->push($key);
            }
        }

        $columns = $columns->push('notes');

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $columns->all());

            foreach ($this->previewRows as $result) {
                $row = [];

                foreach ($columns as $column) {
                    $row[] = $column === 'notes'
                        ? $this->sanitize($result['notes'])
                        : $this->sanitize((string) ($result['original'][$column] ?? ''));
                }

                fputcsv($out, $row);
            }

            fclose($out);
        }, 'service-connections-preview-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function updatedData(): void
    {
        if (blank($this->data['csvFile'] ?? null)) {
            return;
        }

        $this->preview();
    }

    public function preview(): void
    {
        $this->importedCount = 0;
        $this->failedRows = [];

        $file = $this->uploadedCsvFile();

        if (! $file) {
            Notification::make()->title('Please upload a CSV file.')->warning()->send();

            return;
        }

        $path = $file->getRealPath();

        $rows = Excel::toArray(new ServiceConnectionImport, $path);

        if (empty($rows[0])) {
            Notification::make()->title('CSV file is empty or has no valid rows.')->warning()->send();

            return;
        }

        $service = app(ServiceConnectionService::class);
        $headerErrors = $service->validateHeaders($rows[0][0] ?? []);

        if (! empty($headerErrors)) {
            Notification::make()
                ->title('Invalid CSV header')
                ->body(
                    'Required columns: name, barangay, address. Optional: account_number, meter_number, phone, email, gender, birthdate, civil_status, occupation, status, connection_date, rate_schedule. Blank account/meter numbers are auto-generated. '
                    .implode(' ', $headerErrors)
                )
                ->danger()
                ->send();

            return;
        }

        $results = $service->prepareImportRows($rows[0]);

        $this->previewRows = $results;
        $this->validCount = $results->where('valid', true)->count();
        $this->invalidCount = $results->where('valid', false)->count();
        $this->hasPreview = true;
    }

    public function import(): void
    {
        if (! $this->hasPreview || $this->validCount === 0) {
            Notification::make()->title('No valid rows to import.')->warning()->send();

            return;
        }

        $service = app(ServiceConnectionService::class);
        $importerId = Filament::auth()->id();
        $imported = 0;
        $failed = 0;
        $failedRows = [];

        $validRows = $this->previewRows->where('valid', true);

        DB::transaction(function () use ($service, $importerId, $validRows, &$imported, &$failed, &$failedRows) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('select pg_advisory_xact_lock(?)', [self::IMPORT_ADVISORY_LOCK_KEY]);
            }

            foreach ($validRows as $row) {
                try {
                    $row['data']['imported_by'] = $importerId;
                    $service->createWithIdentifierBackstops($row['data'], $row['generated']);
                    $imported++;
                } catch (\Throwable $e) {
                    $failed++;
                    $failedRows[] = $row['row'];
                    Log::warning('Service connection import row failed.', [
                        'row' => $row['row'],
                        'name' => $row['name'] ?? null,
                        'account_number' => $row['account_number'] ?? null,
                        'meter_number' => $row['meter_number'] ?? null,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });

        $this->importedCount = $imported;
        $this->failedRows = $failedRows;
        $this->hasPreview = false;
        $this->previewRows = collect();
        $this->data = [];

        $title = "Imported {$imported} connection(s)."
            .($failed ? " {$failed} row(s) failed." : '');

        $body = $failed
            ? 'Failed CSV rows: '.implode(', ', $failedRows).' - see storage/logs/laravel.log for reasons.'
            : null;

        Notification::make()
            ->title($title)
            ->body($body)
            ->{$failed ? 'warning' : 'success'}()
            ->send();

        AdminNotifier::notify(
            'Service connections imported',
            $body !== null ? $title.' '.$body : $title,
            $failed ? 'warning' : 'success',
        );

        Log::info('Service connection import completed.', [
            'imported_by' => $importerId,
            'imported' => $imported,
            'failed' => $failed,
            'failed_rows' => $failedRows,
        ]);
    }

    public function getTitle(): string
    {
        return 'Import Service Connections';
    }

    protected function uploadedCsvFile(): ?TemporaryUploadedFile
    {
        $file = $this->data['csvFile'] ?? null;

        if (is_array($file)) {
            $file = collect($file)->first();
        }

        return $file instanceof TemporaryUploadedFile ? $file : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Connections')
                ->url(ServiceConnectionResource::getUrl('index')),
        ];
    }
}
