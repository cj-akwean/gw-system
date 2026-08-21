<x-filament-widgets::widget>
    @php
        $data = $this->getData();
        $overdue = $data['overdue'];
        $pendingConnections = $data['pendingConnections'];
        $lowStock = $data['lowStock'];
        $billingRuns = $data['billingRuns'];
        $unreadCount = $data['unreadCount'];
        $urls = $data['urls'];
    @endphp
    <style>
        .gws-wi { display: grid; gap: 1.25rem; }
        .gws-wi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); gap: 1rem; }
        .gws-wi-list { display: flex; flex-direction: column; gap: .5rem; margin: 0; padding: 0; list-style: none; }
        .gws-wi-link { color: inherit; text-decoration: none; }
        .gws-wi-link:hover strong { text-decoration: underline; }
        .gws-wi-muted { color: var(--gray-400); }
        .gws-wi-muted:where(.dark, .dark *) { color: rgba(255, 255, 255, .45); }
    </style>

    <x-filament::section
        :heading="$this->getHeading()"
        icon="heroicon-o-exclamation-triangle"
    >
        @php
            $peso = fn (float $amount): string => '₱'.number_format($amount, 2);
            $total = $overdue->sum('amount');
        @endphp

        <div class="gws-wi">

            @if ($unreadCount > 0)
                <div style="display:flex;align-items:center;gap:.5rem">
                    <x-filament::badge color="primary" icon="heroicon-o-bell">
                        {{ $unreadCount }} unread {{ $unreadCount === 1 ? 'notification' : 'notifications' }}
                    </x-filament::badge>
                    <a href="{{ $urls['notifications'] }}" style="font-size:.75rem;color:var(--primary-600);text-decoration:underline">
                        View notification hub
                    </a>
                </div>
            @endif

            @if ($overdue->isEmpty() && $pendingConnections->isEmpty() && $lowStock->isEmpty() && $billingRuns->isEmpty())
                <div style="display:flex;align-items:center;gap:.75rem;padding:1rem 0">
                    <x-filament::icon icon="heroicon-o-check-circle" class="fi-size-lg" style="color:var(--success-500)" />
                    <span class="gws-wi-muted">Nothing needs your attention right now.</span>
                </div>
            @else
                <div class="gws-wi-grid">

                    @if ($overdue->isNotEmpty())
                        <x-filament::section heading="Overdue bills" description="{{ $overdue->count() }} open · {{ $peso($total) }}" compact>
                            <ul class="gws-wi-list">
                                @foreach ($overdue as $row)
                                    <li>
                                        <a href="{{ $urls['overdue']($row) }}" class="gws-wi-link">
                                            <strong>{{ $row['invoice_number'] }}</strong>
                                            <span class="gws-wi-muted"> · {{ $row['registered_name'] }}</span>
                                            <span class="gws-wi-muted"> · due {{ $row['due_date'] }}</span>
                                            <span style="color:var(--danger-500)"> · {{ $peso($row['amount']) }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </x-filament::section>
                    @endif

                    @if ($pendingConnections->isNotEmpty())
                        <x-filament::section heading="Pending connections" description="{{ $pendingConnections->count() }} awaiting activation" compact>
                            <ul class="gws-wi-list">
                                @foreach ($pendingConnections as $row)
                                    <li>
                                        <a href="{{ $urls['connection']($row) }}" class="gws-wi-link">
                                            <strong>{{ $row['account_number'] }}</strong>
                                            <span class="gws-wi-muted"> · {{ $row['registered_name'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </x-filament::section>
                    @endif

                    @if ($lowStock->isNotEmpty())
                        <x-filament::section heading="Low stock" description="{{ $lowStock->count() }} items below reorder level" compact>
                            <ul class="gws-wi-list">
                                @foreach ($lowStock as $row)
                                    <li style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                                        <a href="{{ $urls['item']($row) }}" class="gws-wi-link">
                                            <strong>{{ $row['name'] }}</strong>
                                        </a>
                                        <x-filament::badge :color="$row['status'] === 'no_stock' ? 'danger' : 'warning'">
                                            {{ $row['status'] === 'no_stock' ? 'No stock' : 'Low stock' }}
                                        </x-filament::badge>
                                    </li>
                                @endforeach
                            </ul>
                        </x-filament::section>
                    @endif

                    @if ($billingRuns->isNotEmpty())
                        <x-filament::section heading="Billing runs" description="Failed or stuck runs" compact>
                            <ul class="gws-wi-list">
                                @foreach ($billingRuns as $row)
                                    <li style="display:flex;align-items:center;justify-content:space-between;gap:.5rem">
                                        <a href="{{ $urls['run']($row) }}" class="gws-wi-link">
                                            <strong>Run #{{ $row['run_id'] }}</strong>
                                            <span class="gws-wi-muted"> · {{ $row['period_end'] }}</span>
                                        </a>
                                        <x-filament::badge :color="$row['is_stale'] ? 'warning' : 'danger'">
                                            {{ $row['is_stale'] ? 'Stale' : ucfirst($row['status']) }}
                                        </x-filament::badge>
                                    </li>
                                @endforeach
                            </ul>
                        </x-filament::section>
                    @endif

                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>