<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section
            heading="Upload CSV File"
            description="Required columns: name, barangay, address. Optional: account_number, meter_number, phone, email, gender, birthdate, civil_status, occupation, status, connection_date, rate_schedule. Any other columns are ignored. Blank account/meter numbers are auto-generated and shown in the preview.">
            <div class="mb-4 flex items-center gap-3">
                <x-filament::button wire:click="downloadTemplate" color="gray" size="sm">
                    Download Template
                </x-filament::button>
                <div wire:loading wire:target="preview" class="flex items-center gap-2 text-sm text-gray-500">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    Analyzing CSV…
                </div>
            </div>
            {{ $this->importForm }}
        </x-filament::section>

        @if ($hasPreview)
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <x-filament::badge color="success">
                            {{ $validCount }} valid
                        </x-filament::badge>
                        <x-filament::badge color="danger">
                            {{ $invalidCount }} invalid
                        </x-filament::badge>
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($validCount > 0)
                            <x-filament::button
                                wire:click="import"
                                color="success"
                                :disabled="$processing"
                            >
                                <span wire:loading.remove wire:target="import">Import {{ $validCount }} Valid Connection(s)</span>
                                <span wire:loading wire:target="import">
                                    <x-filament::loading-indicator class="h-4 w-4" />
                                    Importing…
                                </span>
                            </x-filament::button>
                        @endif
                        <x-filament::button
                            wire:click="downloadCsv"
                            color="gray"
                            :disabled="$processing"
                        >
                            Download CSV with notes
                        </x-filament::button>
                    </div>
                </div>

                <div class="overflow-x-auto border rounded-lg">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-800">
                                <th class="px-4 py-2 text-left font-medium">Row</th>
                                <th class="px-4 py-2 text-left font-medium">Status</th>
                                <th class="px-4 py-2 text-left font-medium">Name</th>
                                <th class="px-4 py-2 text-left font-medium">Account</th>
                                <th class="px-4 py-2 text-left font-medium">Meter</th>
                                <th class="px-4 py-2 text-left font-medium">Barangay</th>
                                <th class="px-4 py-2 text-left font-medium">Status</th>
                                <th class="px-4 py-2 text-left font-medium">Connected</th>
                                <th class="px-4 py-2 text-left font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($previewRows as $result)
                                <tr class="border-t {{ $result['valid'] ? '' : 'bg-danger-50 dark:bg-danger-900/20' }}">
                                    <td class="px-4 py-2">{{ $result['row'] }}</td>
                                    <td class="px-4 py-2">
                                        @if ($result['valid'])
                                            <x-filament::badge color="success">Valid</x-filament::badge>
                                        @else
                                            <x-filament::badge color="danger">Invalid</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $result['name'] ?: '—' }}</td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ $result['account_number'] ?: '—' }}
                                        @if ($result['generated']['account_number'])
                                            <x-filament::badge color="info">auto</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ $result['meter_number'] ?: '—' }}
                                        @if ($result['generated']['meter_number'])
                                            <x-filament::badge color="info">auto</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $result['barangay'] ?: '—' }}</td>
                                    <td class="px-4 py-2">{{ ucfirst($result['status'] ?: '—') }}</td>
                                    <td class="px-4 py-2">{{ $result['connection_date'] ?: '—' }}</td>
                                    <td class="px-4 py-2 text-xs">
                                        @if (!empty($result['notes']))
                                            <span class="{{ $result['valid'] ? 'text-warning-600' : 'text-danger-600' }}">
                                                {{ $result['notes'] }}
                                            </span>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($invalidCount > 0)
                    <div class="mt-4 p-3 bg-warning-50 dark:bg-warning-900/20 rounded-lg text-sm text-warning-700">
                        Rows with errors were skipped. Download the CSV with notes, fix the rows offline, and re-upload to import them.
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($importedCount > 0)
            <div class="p-4 bg-success-50 dark:bg-success-900/20 rounded-lg">
                <p class="text-success-700 font-medium">
                    Successfully imported {{ $importedCount }} service connection(s).
                </p>
                <div class="mt-2">
                    <x-filament::button
                        :href="\App\Filament\Resources\ServiceConnectionResource::getUrl('index')"
                        tag="a"
                        color="primary">
                        View All Connections
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>