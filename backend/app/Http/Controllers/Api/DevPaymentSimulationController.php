<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvoiceNotPayableException;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\PaymentSimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Dev-only harness: lets a browser button fire the same payment.paid
 * simulation the CLI offers (QR Ph has a PayMongo test_url simulator; Google
 * Pay's sheet cannot open on the dev laptop and PayMongo offers no
 * google_pay_card simulator, so the webhook-crediting half needs an HTTP
 * surface). The endpoint does not exist in production — the frontend link is
 * additionally gated on a pk_test_ key, so a stray tap can never mark a live
 * invoice paid.
 */
class DevPaymentSimulationController extends Controller
{
    public function store(Request $request, PaymentSimulationService $simulation): JsonResponse
    {
        abort_unless(! app()->environment('production'), 404);

        $request->validate(['invoice_id' => 'required|integer']);

        $invoice = Invoice::query()->find($request->integer('invoice_id'));

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

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
            // forceFreshIntent: the simulated Google Pay payment must never be
            // attributed to a stored intent left over from another flow (e.g.
            // an expired QR Ph code) — it always gets its own pi_sim_ intent.
            $result = $simulation->simulate($invoice, 'google_pay_card', null, true);
        } catch (InvoiceNotPayableException) {
            // Race fallback — the payable guard above already handled 409.
            return response()->json(['message' => 'Invoice is not payable.'], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The test payment could not be completed. Please try again.'], 500);
        }

        return response()->json([
            'message' => 'Simulated payment.paid for invoice '.$invoice->invoice_number.'.',
            'payment_id' => $result['payment_id'],
            'event_id' => $result['event_id'],
            'source' => 'google_pay_card',
        ]);
    }
}