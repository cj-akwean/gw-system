<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BillingRunResource;
use App\Filament\Resources\InventoryItemResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\ServiceConnectionResource;
use App\Services\DashboardMetricsService;
use Filament\Widgets\Widget;
use Illuminate\Contracts\Support\Htmlable;

class NeedsAttentionWidget extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.needs-attention';

    public function getData(): array
    {
        $data = app(DashboardMetricsService::class)->needsAttention();

        return [
            'overdue' => $data['overdue'],
            'pendingConnections' => $data['pending_connections'],
            'lowStock' => $data['low_stock'],
            'billingRuns' => $data['billing_runs'],
            'unreadCount' => $data['unread_count'],
            'urls' => [
                'overdue' => fn (array $row): string => InvoiceResource::getUrl('view', ['record' => $row['invoice_id']]),
                'connection' => fn (array $row): string => ServiceConnectionResource::getUrl('view', ['record' => $row['connection_id']]),
                'item' => fn (array $row): string => InventoryItemResource::getUrl('view', ['record' => $row['item_id']]),
                'run' => fn (array $row): string => BillingRunResource::getUrl('view', ['record' => $row['run_id']]),
                'notifications' => '/admin/notifications',
            ],
        ];
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Needs your attention';
    }
}