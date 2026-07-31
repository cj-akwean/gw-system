<x-filament-panels::page>
    <div class="space-y-6">
        <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <h3 class="text-sm font-medium mb-2">Upload CSV File</h3>
            <p class="text-xs text-gray-500 mb-4">
                Expected columns: <code>account_number</code>, <code>meter_number</code>, <code>present_reading</code>, <code>reading_date</code>
            </p>

            <input
                type="file"
                accept=".csv,text/csv,text/plain,application/vnd.ms-excel"
                wire:model="csvFile"
                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"
            />

            @error('csvFile')
                <p class="text-sm text-danger-600 mt-1">{{ $message }}</p>
            @enderror
        </div>

        @if ($hasPreview)
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div class="flex gap-4 text-sm">
                        <span class="text-success-700 dark:text-success-500 font-medium">
                            {{ $validCount }} valid
                        </span>
                        <span class="text-danger-700 dark:text-danger-500 font-medium">
                            {{ $invalidCount }} invalid
                        </span>
                    </div>
                    @if ($validCount > 0)
                        <x-filament::button wire:click="import" color="success">
                            Import {{ $validCount }} Valid Reading(s)
                        </x-filament::button>
                    @endif
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
                                            <span class="text-success-700 font-medium">Valid</span>
                                            @if ($result['flagged'] ?? false)
                                                <span class="text-warning-700 ml-1">(flagged)</span>
                                            @endif
                                        @else
                                            <span class="text-danger-700 font-medium">Invalid</span>
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
                                        @if (!empty($result['errors']))
                                            <ul class="list-disc list-inside text-danger-600">
                                                @foreach ($result['errors'] as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        @elseif($result['flagged'] ?? false)
                                            <span class="text-warning-600">Present reading is lower than previous (meter may have been replaced)</span>
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
                        Rows with errors were skipped. Fix the CSV and re-upload to import them.
                    </div>
                @endif
            </div>
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
