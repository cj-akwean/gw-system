<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Collection;

class PortalBillsService
{
    /**
     * Unpaid + overdue invoices across the user's active linked connections,
     * overdue first, then by due date.
     */
    public function unpaidInvoices(User $user): Collection
    {
        $connectionIds = $user->connectionLinks()
            ->where('status', 'active')
            ->pluck('service_connection_id');

        if ($connectionIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->with('serviceConnection.barangay')
            ->whereIn('service_connection_id', $connectionIds)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderByRaw("case when status = 'overdue' then 0 else 1 end")
            ->orderBy('due_date')
            ->get();
    }
}
