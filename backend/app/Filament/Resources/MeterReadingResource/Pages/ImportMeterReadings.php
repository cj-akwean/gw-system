<?php

namespace App\Filament\Resources\MeterReadingResource\Pages;

use App\Filament\Resources\MeterReadingResource;
use App\Imports\MeterReadingImport;
use App\Services\ReadingService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;

class ImportMeterReadings extends Page
{
    protected static string $resource = MeterReadingResource::class;

    protected string $view = 'filament.pages.import-meter-readings';

    public Collection $previewRows;

    public bool $hasPreview = false;

    public int $validCount = 0;

    public int $invalidCount = 0;

    public int $importedCount = 0;

    public array $data = [];

    public function mount(): void
    {
        $this->previewRows = collect();
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

    public function downloadTemplate()
    {
        $headers = ['account_number', 'meter_number', 'present_reading', 'reading_date', 'flagged'];
        $rows = [
            ['ACC-001', 'MTR-001', '12345.67', '2026-08-01', '0'],
            ['ACC-002', 'MTR-002', '67890.12', '2026-08-01', '1'],
        ];

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'meter-readings-template.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function downloadCsv()
    {
        if (! $this->hasPreview) {
            return;
        }

        $columns = collect();

        foreach ($this->previewRows as $result) {
            foreach (array_keys($result['original']) as $key) {
                if (strtolower((string) $key) === 'flagged' || $columns->contains($key)) {
                    continue;
                }
                $columns->push($key);
            }
        }

        $columns = $columns->push('notes', 'flagged');

        return response()->streamDownload(function () use ($columns) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $columns->all());

            foreach ($this->previewRows as $result) {
                $row = [];

                foreach ($columns as $column) {
                    $row[] = match ($column) {
                        'notes' => $result['notes'],
                        'flagged' => (int) ($result['data']['flagged'] ?? 0),
                        default => $result['original'][$column] ?? '',
                    };
                }

                fputcsv($out, $row);
            }

            fclose($out);
        }, 'meter-readings-preview-'.now()->format('Ymd-His').'.csv', [
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
        $file = $this->uploadedCsvFile();

        if (! $file) {
            Notification::make()->title('Please upload a CSV file.')->warning()->send();

            return;
        }

        $path = $file->getRealPath();

        $rows = Excel::toArray(new MeterReadingImport(app(ReadingService::class)), $path);

        if (empty($rows[0])) {
            Notification::make()->title('CSV file is empty or has no valid rows.')->warning()->send();

            return;
        }

        $importService = app(ReadingService::class);
        $headerErrors = $importService->validateHeaders($rows[0][0] ?? []);

        if (! empty($headerErrors)) {
            Notification::make()
                ->title('Invalid CSV header')
                ->body(
                    'Expected columns: account_number and/or meter_number, present_reading, reading_date (optional). '
                    .implode(' ', $headerErrors)
                )
                ->danger()
                ->send();

            return;
        }

        $user = Filament::auth()->user();
        $results = $importService->prepareImportRows($rows[0], $user);

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

        $user = Filament::auth()->user();
        $service = app(ReadingService::class);
        $imported = 0;
        $failed = 0;

        $validRows = $this->previewRows->where('valid', true);

        DB::transaction(function () use ($service, $user, $validRows, &$imported, &$failed) {
            foreach ($validRows as $row) {
                try {
                    $service->createFromArray($row['data'], $user, 'csv_import');
                    $imported++;
                } catch (\Exception $e) {
                    $failed++;
                }
            }
        });

        $this->importedCount = $imported;
        $this->hasPreview = false;
        $this->previewRows = collect();
        $this->data = [];

        $title = "Imported {$imported} reading(s)."
            . ($failed ? " {$failed} row(s) failed." : '');

        Notification::make()
            ->title($title)
            ->{$failed ? 'warning' : 'success'}()
            ->send();
    }

    public function getTitle(): string
    {
        return 'Import Meter Readings';
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
                ->label('Back to Readings')
                ->url(MeterReadingResource::getUrl('index')),
        ];
    }
}
