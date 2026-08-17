<x-filament-panels::page>
    <style>
        .gws-card { background: var(--color-white); border: 1px solid var(--gray-200); border-radius: 1rem; padding: 1rem; display: flex; gap: .875rem; align-items: center; }
        .gws-card:where(.dark, .dark *) { background: rgba(255, 255, 255, .04); border-color: rgba(255, 255, 255, .10); }

        .gws-icon { flex: none; color: var(--gray-400); }
        .gws-icon:where(.dark, .dark *) { color: rgba(255, 255, 255, .45); }

        .gws-label { font-size: .75rem; font-weight: 500; color: var(--gray-500); }
        .gws-label:where(.dark, .dark *) { color: rgba(255, 255, 255, .65); }

        .gws-caption { font-size: .75rem; color: var(--gray-400); }
        .gws-caption:where(.dark, .dark *) { color: rgba(255, 255, 255, .45); }

        .gws-value { font-size: 1.5rem; font-weight: 700; margin-top: .125rem; }

        .gws-field-label { display: block; margin-bottom: .375rem; font-size: .75rem; font-weight: 500; color: var(--gray-500); }
        .gws-field-label:where(.dark, .dark *) { color: rgba(255, 255, 255, .65); }

        .gws-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .gws-th { text-align: left; padding: .625rem .75rem; font-weight: 500; text-transform: uppercase; font-size: .75rem; letter-spacing: .02em; color: var(--gray-500); }
        .gws-th:where(.dark, .dark *) { color: rgba(255, 255, 255, .55); }
        .gws-thead { background: var(--gray-50); }
        .gws-thead:where(.dark, .dark *) { background: rgba(255, 255, 255, .06); }
        .gws-td { padding: .625rem .75rem; }
        .gws-right { text-align: right; white-space: nowrap; }
        .gws-row { border-top: 1px solid var(--gray-100); }
        .gws-row:where(.dark, .dark *) { border-top-color: rgba(255, 255, 255, .08); }
        .gws-total { border-top: 1px solid var(--gray-200); background: var(--gray-50); font-weight: 600; }
        .gws-total:where(.dark, .dark *) { border-top-color: rgba(255, 255, 255, .16); background: rgba(255, 255, 255, .06); }

        .gws-track { flex: 1; min-width: 5rem; max-width: 8rem; height: .5rem; border-radius: 9999px; background: var(--gray-100); overflow: hidden; }
        .gws-track:where(.dark, .dark *) { background: rgba(255, 255, 255, .14); }

        .gws-footnote { font-size: .75rem; color: var(--gray-400); margin: 0; }
        .gws-footnote:where(.dark, .dark *) { color: rgba(255, 255, 255, .45); }
    </style>

    @php
        $report = $this->reportData();
        $aging = $report['aging'];
        $income = $report['income'];
        $totalAmount = (float) $aging->sum('amount');
        $pct = fn (float $amount): float => $totalAmount > 0 ? round($amount / $totalAmount * 100, 1) : 0.0;
        $badgeColor = fn (string $key): string => match ($key) {
            'current' => 'gray',
            'd31_60' => 'warning',
            'd61_90' => 'warning',
            default => 'danger',
        };
        $barColor = fn (string $key): string => match ($key) {
            'current' => 'var(--gray-400)',
            'd31_60' => 'var(--warning-500)',
            'd61_90' => 'var(--warning-500)',
            default => 'var(--danger-500)',
        };
        $amountStyle = fn (string $key): string => $key === 'overdue90' ? 'color:var(--danger-500);font-weight:600' : '';

        $peso = fn (float $amount): string => '₱'.number_format($amount, 2);

        $metricCards = [
            ['icon' => 'heroicon-o-banknotes', 'label' => 'Total receivables', 'value' => $peso($report['summary']['total_receivables']), 'caption' => 'All unpaid + overdue invoices, as of today'],
            ['icon' => 'heroicon-o-arrow-trending-up', 'label' => 'Total collections', 'value' => $peso($report['summary']['total_collections']), 'caption' => 'Payments recorded in the selected period'],
        ];

        $incomeCards = [
            ['icon' => 'heroicon-o-document-text', 'label' => 'Gross billed revenue', 'value' => $peso($income['gross_billed']), 'caption' => 'Total invoiced in the period (accrual)'],
            ['icon' => 'heroicon-o-arrow-trending-up', 'label' => 'Cash collections', 'value' => $peso($income['cash_collections']), 'caption' => 'Cash, GCash and bank payments (cash basis)'],
            ['icon' => 'heroicon-o-banknotes', 'label' => 'Miscellaneous income', 'value' => $peso($income['misc_income']), 'caption' => 'Penalty charges billed in the period'],
        ];
    @endphp

    <div style="display:grid;gap:1.25rem">

        {{-- Period selector --}}
        <x-filament::section heading="Reporting period" icon="heroicon-o-calendar-days" description="Drives collections, the income statement and the exports. Receivables and AR aging are as of today.">
            <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end">
                <div>
                    <label class="gws-field-label">Preset</label>
                    <x-filament::input.select wire:model.live="preset" style="width:11rem">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                        <option value="custom">Custom</option>
                    </x-filament::input.select>
                </div>
                <div>
                    <label class="gws-field-label">From</label>
                    <x-filament::input.wrapper>
                        <input type="date" wire:model.live="from" class="fi-input" style="min-width:10rem">
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="gws-field-label">To</label>
                    <x-filament::input.wrapper>
                        <input type="date" wire:model.live="to" class="fi-input" style="min-width:10rem">
                    </x-filament::input.wrapper>
                </div>
                <div style="padding-bottom:.25rem">
                    <x-filament::badge color="gray" icon="heroicon-o-calendar-days">{{ $report['range']['label'] }}</x-filament::badge>
                </div>
            </div>
        </x-filament::section>

        {{-- Receivables vs collections --}}
        <x-filament::section heading="Receivables vs collections" icon="heroicon-o-scale" description="{{ $report['range']['label'] }}">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem">
                @foreach ($metricCards as $card)
                    <div class="gws-card">
                        <x-filament::icon :icon="$card['icon']" class="fi-size-lg gws-icon" />
                        <div>
                            <div class="gws-label">{{ $card['label'] }}</div>
                            <div class="gws-value">{{ $card['value'] }}</div>
                            <div class="gws-caption" style="margin-top:.375rem">{{ $card['caption'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        {{-- AR aging --}}
        <x-filament::section heading="Accounts receivable aging" icon="heroicon-o-clock" description="Age is days past the invoice due date, as of {{ $report['generatedAt'] }}. Penalties are the amounts charged on each bill.">
            <div style="overflow-x:auto">
                <table class="gws-table">
                    <thead class="gws-thead">
                        <tr>
                            <th class="gws-th">Aging bucket</th>
                            <th class="gws-th gws-right">Invoices</th>
                            <th class="gws-th gws-right">Outstanding</th>
                            <th class="gws-th gws-right">Penalties</th>
                            <th class="gws-th gws-right">Share of receivables</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($aging as $bucket)
                            <tr class="gws-row">
                                <td class="gws-td">
                                    <div style="display:flex;align-items:center;gap:.5rem">
                                        <x-filament::badge :color="$badgeColor($bucket['key'])">{{ $bucket['label'] }}</x-filament::badge>
                                    </div>
                                    <div class="gws-caption" style="margin-top:.25rem">{{ $bucket['range_label'] }}</div>
                                </td>
                                <td class="gws-td gws-right">{{ number_format($bucket['count']) }}</td>
                                <td class="gws-td gws-right" style="{{ $amountStyle($bucket['key']) }}">{{ $peso($bucket['amount']) }}</td>
                                <td class="gws-td gws-right">{{ $peso($bucket['penalty']) }}</td>
                                <td class="gws-td">
                                    <div style="display:flex;align-items:center;justify-content:flex-end;gap:.5rem">
                                        <div class="gws-track">
                                            <div style="width:{{ $pct($bucket['amount']) }}%;height:100%;background:{{ $barColor($bucket['key']) }}"></div>
                                        </div>
                                        <span class="gws-caption">{{ $pct($bucket['amount']) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="gws-row">
                                <td colspan="5" class="gws-td gws-caption" style="padding:1.25rem;text-align:center">No outstanding receivables.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="gws-total">
                            <td class="gws-td">Total</td>
                            <td class="gws-td gws-right">{{ number_format($aging->sum('count')) }}</td>
                            <td class="gws-td gws-right">{{ $peso($aging->sum('amount')) }}</td>
                            <td class="gws-td gws-right">{{ $peso($aging->sum('penalty')) }}</td>
                            <td class="gws-td gws-right gws-label">100%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-filament::section>

        {{-- Statement of income --}}
        <x-filament::section heading="Statement of income — cash vs accrual" icon="heroicon-o-receipt-percent" description="{{ $report['range']['label'] }}">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));gap:1rem">
                @foreach ($incomeCards as $card)
                    <div class="gws-card">
                        <x-filament::icon :icon="$card['icon']" class="fi-size-lg gws-icon" />
                        <div>
                            <div class="gws-label">{{ $card['label'] }}</div>
                            <div class="gws-value">{{ $card['value'] }}</div>
                            <div class="gws-caption" style="margin-top:.375rem">{{ $card['caption'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-top:1rem">
                <x-filament::callout
                    :color="$income['net_operating_income'] < 0 ? 'danger' : 'success'"
                    icon="heroicon-o-scale"
                    :heading="$peso($income['net_operating_income']).' net operating income'"
                    :description="$peso($income['gross_billed']).' gross billed + '.$peso($income['misc_income']).' miscellaneous income − '.$peso($income['cash_collections']).' collections. Reconnection and setup fees are not tracked yet, so they report as ₱0.'"
                />
            </div>
        </x-filament::section>

        {{-- Payment breakdown --}}
        <x-filament::section heading="Payment breakdown & reconciliation" icon="heroicon-o-document-text" description="Filter by payment date and method; independent of the reporting period above.">
            {{ $this->table }}
        </x-filament::section>

        <p class="gws-footnote">
            Auto-generated on {{ $report['generatedAt'] }} by the Guinobatan Waterworks System.
        </p>
    </div>
</x-filament-panels::page>
