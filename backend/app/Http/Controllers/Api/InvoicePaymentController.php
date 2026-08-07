<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvoiceNotPayableException;
use App\Exceptions\PaymentAlreadyCompletedException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PayMongoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvoicePaymentController extends Controller
{
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
