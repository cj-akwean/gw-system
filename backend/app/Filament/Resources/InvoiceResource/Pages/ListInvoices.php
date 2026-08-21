<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Exports\InvoicesExport;
use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function (): BinaryFileResponse {
                    return Excel::download(
                        new InvoicesExport($this->getTableQueryForExport()),
                        'invoices-' . now()->format('Y-m-d-His') . '.csv',
                    );
                }),
        ];
    }

    /**
     * Status tabs so the cashier can segment work (unpaid to follow up vs
     * overdue to chase) without building a filter each time. Counts keep the
     * backlog visible at a glance.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->badge(Invoice::count()),
            'unpaid' => Tab::make('Unpaid')
                ->query(fn (Builder $query): Builder => $query->where('status', 'unpaid'))
                ->badge(Invoice::query()->where('status', 'unpaid')->count())
                ->badgeColor('warning'),
            'overdue' => Tab::make('Overdue')
                ->query(fn (Builder $query): Builder => $query->where('status', 'overdue'))
                ->badge(Invoice::query()->where('status', 'overdue')->count())
                ->badgeColor('danger'),
            'paid' => Tab::make('Paid')
                ->query(fn (Builder $query): Builder => $query->where('status', 'paid'))
                ->badge(Invoice::query()->where('status', 'paid')->count())
                ->badgeColor('success'),
        ];
    }
}
