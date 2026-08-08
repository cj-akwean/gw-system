<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvoiceNotPayableException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\SavedPaymentMethod;
use App\Services\PayMongoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvoicePaymentController extends Controller
{
    /**
     * Starts a payment using a previously saved card.
     * The frontend must later attach the saved payment_method_id to the intent.
     */
    public function payWithSaved(Request $request, Invoice $invoice, PayMongoService $payMongo): JsonResponse
    {
        $user = $request->user();

        $linked = $user->connectionLinks()
            ->where('service_connection_id', $invoice->service_connection_id)
            ->where('status', 'active')
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            $message = $invoice->status === 'paid'
                ? 'Invoice is already paid.'
                : 'Invoice is not payable.';

            return response()->json(['message' => $message], 409);
        }

        $request->validate([
            'payment_method_id' => 'required|string',
            'cvc' => 'required|string|min:3|max:4',
        ]);

        $savedMethod = $user->savedPaymentMethods()
            ->where('paymongo_payment_method_id', $request->input('payment_method_id'))
            ->first();

        if ($savedMethod === null) {
            return response()->json(['message' => 'Payment method not found.'], 404);
        }

        if ($savedMethod->is_expired) {
            return response()->json(['message' => 'This card has expired. Please use a different payment method.'], 422);
        }

        try {
            // Update CVC on PayMongo (required before attaching a saved card)
            $payMongo->updatePaymentMethodCvc($savedMethod->paymongo_payment_method_id, $request->input('cvc'));

            $intent = $payMongo->createPaymentIntentForSavedMethod($invoice, $savedMethod->paymongo_payment_method_id);
        } catch (InvoiceNotPayableException) {
            return response()->json(['message' => 'Invoice is not payable.'], 409);
        } catch (PaymentAlreadyCompletedException) {
            return response()->json([
                'message' => 'A payment for this invoice already went through and is being confirmed. If it is not credited shortly, please contact support.',
            ], 409);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Payment gateway unavailable. Please try again.'], 502);
        }

        return response()->json([
            'client_key' => $intent['client_key'],
            'payment_intent_id' => $intent['intent_id'],
            'expiry_seconds' => (int) config('services.paymongo.qr_expiry_seconds'),
            'payment_method_id' => $savedMethod->paymongo_payment_method_id,
        ]);
    }
    public function store(Request $request, Invoice $invoice, PayMongoService $payMongo): JsonResponse
    {
        $linked = $request->user()->connectionLinks()
            ->where('service_connection_id', $invoice->service_connection_id)
            ->where('status', 'active')
            ->exists();

        if (! $linked) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! in_array($invoice->status, ['unpaid', 'overdue'], true)) {
            $message = $invoice->status === 'paid'
                ? 'Invoice is already paid.'
                : 'Invoice is not payable.';

            return response()->json(['message' => $message], 409);
        }

        // Card vaulting is DISABLED: PayMongo rejects setup_future_usage on
        // this account ("On session payments are not yet supported." — vaulting
        // needs the account capability, contact PayMongo support). The plain
        // intent path is the only one used; re-enable alongside the capability.
        try {
            $intent = $payMongo->getOrCreatePaymentIntent($invoice);
        } catch (InvoiceNotPayableException) {
            return response()->json(['message' => 'Invoice is not payable.'], 409);
        } catch (PaymentAlreadyCompletedException) {
            return response()->json([
                'message' => 'A payment for this invoice already went through and is being confirmed. If it is not credited shortly, please contact support.',
            ], 409);
        } catch (ConnectionException|RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Payment gateway unavailable. Please try again.'], 502);
        }

        return response()->json([
            'client_key' => $intent['client_key'],
            'payment_intent_id' => $intent['intent_id'],
            'expiry_seconds' => (int) config('services.paymongo.qr_expiry_seconds'),
        ]);
    }
}
