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

        return response()->json($invoices->map(
            fn (Invoice $invoice) => $this->mapInvoice($invoice)
        ));
    }

    /**
     * A single invoice the user is linked to, any status (paid included).
     * Used by the payment screen to distinguish "just paid, webhook beat the
     * UI" (status paid) from "genuinely not payable" (403 / other status)
     * when the invoice is no longer in the unpaid list.
     */
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $linked = $request->user()->connectionLinks()
            ->where('service_connection_id', $invoice->service_connection_id)
            ->where('status', 'active')
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($this->mapInvoice(
            $invoice->load('serviceConnection.barangay')
        ));
    }

    private function mapInvoice(Invoice $invoice): array
    {
        return [
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
        ];
    }
}
