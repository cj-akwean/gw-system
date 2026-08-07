<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PortalBillsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request, PortalBillsService $bills): JsonResponse
    {
        return response()->json(
            $bills->recentPayments($request->user())->map(function (Payment $payment): array {
                return [
                    'id' => $payment->id,
                    'invoice_number' => $payment->invoice->invoice_number,
                    'billing_period_start' => $payment->invoice->billing_period_start->toDateString(),
                    'billing_period_end' => $payment->invoice->billing_period_end->toDateString(),
                    'amount' => (float) $payment->amount,
                    'method' => $payment->method,
                    'channel' => $payment->paymongo_source,
                    'paid_at' => $payment->paid_at?->toIso8601String(),
                    'service_connection' => [
                        'account_number' => $payment->invoice->serviceConnection->account_number,
                        'meter_number' => $payment->invoice->serviceConnection->meter_number,
                        'registered_name' => $payment->invoice->serviceConnection->registered_name,
                        'barangay' => $payment->invoice->serviceConnection->barangay?->name,
                    ],
                ];
            })
        );
    }
}
