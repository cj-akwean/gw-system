<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section
            heading="Upload CSV File"
            description="Expected columns: account_number and/or meter_number, present_reading, reading_date (optional), flagged (optional). Any other columns are ignored.">
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
                            <x-filament::button wire:click="import" color="success">
                                Import {{ $validCount }} Valid Reading(s)
                            </x-filament::button>
                        @endif
                        <x-filament::button wire:click="downloadCsv" color="gray">
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
                                <th class="px-4 py-2 text-left font-medium">Account</th>
                                <th class="px-4 py-2 text-left font-medium">Meter</th>
                                <th class="px-4 py-2 text-left font-medium">Present</th>
                                <th class="px-4 py-2 text-left font-medium">Previous</th>
                                <th class="px-4 py-2 text-left font-medium">Date</th>
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
                                            @if ($result['flagged'] ?? false)
                                                <x-filament::badge color="warning">Flagged</x-filament::badge>
                                            @endif
                                        @else
                                            <x-filament::badge color="danger">Invalid</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ $result['connection']->account_number ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ $result['connection']->meter_number ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ number_format($result['data']['present_reading'] ?? 0, 2) }}
                                    </td>
                                    <td class="px-4 py-2 font-mono">
                                        {{ number_format($result['data']['previous_reading'] ?? 0, 2) }}
                                    </td>
                                    <td class="px-4 py-2">
                                        {{ ($result['data']['entered_at'] ?? null) instanceof \Carbon\Carbon ? $result['data']['entered_at']->format('Y-m-d') : ($result['data']['entered_at'] ?? '—') }}
                                    </td>
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
                    Successfully imported {{ $importedCount }} meter reading(s).
                </p>
                <div class="mt-2">
                    <x-filament::button
                        :href="\App\Filament\Resources\MeterReadingResource::getUrl('index')"
                        tag="a"
                        color="primary">
                        View All Readings
                    </x-filament::button>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
