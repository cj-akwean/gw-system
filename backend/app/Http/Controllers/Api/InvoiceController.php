<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PortalBillsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request, PortalBillsService $bills): JsonResponse
    {
        $invoices = $bills->unpaidInvoices($request->user());

        return response()->json($invoices->map(fn (Invoice $invoice) => [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'billing_period_start' => $invoice->billing_period_start->toDateString(),
            'billing_period_end' => $invoice->billing_period_end->toDateString(),
            'due_date' => $invoice->due_date->toDateString(),
            'previous_balance' => (float) $invoice->previous_balance,
            'base_amount' => (float) $invoice->base_amount,
            'penalty_amount' => (float) $invoice->penalty_amount,
            'total_amount' => (float) $invoice->total_amount,
            'status' => $invoice->status,
            'service_connection' => [
                'account_number' => $invoice->serviceConnection->account_number,
                'meter_number' => $invoice->serviceConnection->meter_number,
                'registered_name' => $invoice->serviceConnection->registered_name,
                'barangay' => $invoice->serviceConnection->barangay?->name,
            ],
        ]));
    }
}
