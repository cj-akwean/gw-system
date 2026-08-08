<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PayMongoService;
use App\Services\PortalBillsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

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

    /**
     * Resolves a PayMongo payment intent back to the user's invoice so the
     * payment screen can answer "did my payment go through?" on a redirect
     * return, without trusting the redirect (which carries no invoice id) and
     * without waiting for the webhook.
     *
     * - paid: the webhook already credited the invoice
     * - confirmed: PayMongo says the intent succeeded but the invoice is not
     *   credited yet (webhook lagging)
     * - failed: the payment did not complete (declined / cancelled 3DS)
     * - processing: still in flight
     * - unknown: no local invoice holds this intent — never a definitive
     *   negative (the intent may belong to a payment we cannot see); a failed
     *   resolution must never dead-end the UI
     *
     * Every resolved branch (paid/confirmed/failed/processing) carries the
     * invoice_id so the frontend can rebuild the pay screen after a failure
     * and poll a confirmed-but-uncredited payment. 403 only for an intent
     * whose invoice is on a connection the user is not linked to.
     */
    public function intentStatus(Request $request, PayMongoService $payMongo): JsonResponse
    {
        $validated = $request->validate([
            'payment_intent_id' => ['required', 'string', 'max:255'],
        ]);

        $invoice = Invoice::query()
            ->where('paymongo_payment_intent_id', $validated['payment_intent_id'])
            ->first();

        if ($invoice === null) {
            return response()->json(['status' => 'unknown']);
        }

        $linked = $request->user()->connectionLinks()
            ->where('service_connection_id', $invoice->service_connection_id)
            ->where('status', 'active')
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($invoice->status === 'paid') {
            return response()->json([
                'status' => 'paid',
                'invoice_id' => $invoice->id,
            ]);
        }

        try {
            $intentStatus = $payMongo->getPaymentIntentStatus($validated['payment_intent_id']);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Payment gateway unavailable. Please try again.'], 502);
        }

        if ($intentStatus === null) {
            return response()->json(['status' => 'unknown']);
        }

        // Every resolved branch carries invoice_id (the invoice exists — we
        // found it above) so the frontend can rebuild the pay screen after a
        // failure and can keep polling a confirmed-but-uncredited payment.
        return match ($intentStatus) {
            'succeeded' => response()->json([
                'status' => 'confirmed',
                'invoice_id' => $invoice->id,
            ]),
            'failed', 'awaiting_payment_method' => response()->json([
                'status' => 'failed',
                'invoice_id' => $invoice->id,
            ]),
            default => response()->json([
                'status' => 'processing',
                'invoice_id' => $invoice->id,
            ]),
        };
    }
}
