<x-filament-panels::page>
    @php($report = $this->reportData())
    @php($rows = app(\App\Services\FinancialReportService::class)->monthlyRows($report['monthlyRevenue']))

    <div class="space-y-6">
        <x-filament::section heading="Summary" description="Generated {{ $report['generatedAt'] }}">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <x-filament::section :compact="true">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Active customers</div>
                    <div class="mt-1 text-2xl font-bold">{{ number_format($report['summary']['active_connections']) }}</div>
                </x-filament::section>
                <x-filament::section :compact="true">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Unpaid bills</div>
                    <div class="mt-1 text-2xl font-bold">{{ number_format($report['summary']['unpaid_bills']) }}</div>
                </x-filament::section>
                <x-filament::section :compact="true">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Overdue bills</div>
                    <div class="mt-1 text-2xl font-bold text-danger-600">{{ number_format($report['summary']['overdue_bills']) }}</div>
                </x-filament::section>
                <x-filament::section :compact="true">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Outstanding amount</div>
                    <div class="mt-1 text-2xl font-bold text-danger-600">&#8369;{{ number_format($report['summary']['outstanding_amount'], 2) }}</div>
                </x-filament::section>
                <x-filament::section :compact="true">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Revenue this month</div>
                    <div class="mt-1 text-2xl font-bold text-success-600">&#8369;{{ number_format($report['summary']['revenue_this_month'], 2) }}</div>
                </x-filament::section>
            </div>
        </x-filament::section>

        <x-filament::section heading="Revenue by month">
            <div class="overflow-x-auto border rounded-lg">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800">
                            <th class="px-4 py-2 text-left font-medium">Month</th>
                            <th class="px-4 py-2 text-right font-medium">Revenue (&#8369;)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-t">
                                <td class="px-4 py-2">{{ $row['label'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ number_format($row['revenue'], 2) }}</td>
                            </tr>
                        @empty
                            <tr class="border-t">
                                <td class="px-4 py-2 text-gray-400" colspan="2">No revenue data.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
