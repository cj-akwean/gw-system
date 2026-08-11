<?php

namespace App\Http\Controllers\Api;

use App\Filament\Resources\PaymentResource;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PaymentService;
use App\Services\PayMongoService;
use App\Support\AdminNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvoiceReconcileController extends Controller
{
    /**
     * Reconciles a single invoice against PayMongo's intent status.
     *
     * Use-case: the customer returned from redirect, PayMongo says the
     * payment succeeded, but the webhook never arrived (wrong URL, ngrok
     * down, etc.). The customer clicks "Check payment status" — this
     * endpoint verifies the intent and credits the invoice if needed.
     *
     * Read-safe: if the intent is not succeeded, nothing changes.
     * Idempotent: if the invoice is already paid, returns paid.
     */
    public function reconcile(
        Request $request,
        Invoice $invoice,
        PayMongoService $payMongo,
        PaymentService $paymentService,
    ): JsonResponse {
        $user = $request->user();

        $linked = $user->connectionLinks()
            ->where('service_connection_id', $invoice->service_connection_id)
            ->where('status', 'active')
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Already paid — nothing to reconcile.
        if ($invoice->status === 'paid') {
            return response()->json(['status' => 'paid']);
        }

        // No intent stored — nothing to check with PayMongo.
        if ($invoice->paymongo_payment_intent_id === null) {
            return response()->json(['status' => $invoice->status]);
        }

        try {
            $intentStatus = $payMongo->getPaymentIntentStatus($invoice->paymongo_payment_intent_id);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Payment gateway unavailable. Please try again.'], 502);
        }

        if ($intentStatus === null) {
            return response()->json(['status' => $invoice->status]);
        }

        // Intent succeeded but invoice not yet credited — webhook was missed.
        // Credit it now using the same path as the webhook processor.
        if ($intentStatus === 'succeeded' && in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            $intentId = $invoice->paymongo_payment_intent_id;

            // We need the payment id and amount from PayMongo to call markPaidFromWebhook.
            // Retrieve the full intent to get these details.
            try {
                $intentDetails = $payMongo->getPaymentIntent($intentId);
            } catch (ConnectionException|RuntimeException $e) {
                report($e);

                return response()->json(['message' => 'Payment gateway unavailable. Please try again.'], 502);
            }

            // The intent's payment data may not be directly available from
            // getPaymentIntent — fall back to the invoice total and a synthetic
            // payment id built from the intent id (dedupe key).
            $paymentId = $intentDetails['payment_id'] ?? 'pay_intended_'.$intentId;
            $amountCentavos = $intentDetails['amount'] ?? (int) round($invoice->total_amount * 100);

            $payment = $paymentService->markPaidFromWebhook(
                $invoice,
                $paymentId,
                $amountCentavos,
                null, // paid_at — we don't have the exact timestamp
                $intentDetails['source'] ?? null,
                null, // payer_name
                null, // payer_email
                null, // payer_phone
            );

            if ($payment !== null) {
                $channel = PaymentResource::channelLabel($intentDetails['source'] ?? null);

                AdminNotifier::notify(
                    'Payment credited (reconciled)',
                    'Invoice '.$invoice->invoice_number.' — ₱'.number_format((float) $payment->amount, 2)
                        .($channel !== '—' ? ' via '.$channel : '').'. Credited automatically after webhook miss.',
                );
            }

            return response()->json(['status' => 'paid']);
        }

        // Intent failed or still processing — report current state.
        return response()->json(['status' => $invoice->status]);
    }
}
