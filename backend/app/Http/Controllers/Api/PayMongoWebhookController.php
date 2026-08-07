<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayMongoWebhook;
use App\Services\PayMongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayMongoWebhookController extends Controller
{
    /**
     * Event types this integration understands. Anything else is acknowledged
     * and skipped — an error response would trigger PayMongo's retry logic.
     */
    private const KNOWN_EVENT_TYPES = [
        'payment.paid',
        'payment.failed',
        'payment_intent.succeeded',
        'payment_intent.awaiting_payment_method',
    ];

    public function store(Request $request, PayMongoService $payMongo): JsonResponse
    {
        $rawBody = $request->getContent();

        // Only the spellings "Paymongo-Signature" (current docs) and the legacy
        // "X-Paymongo-Signature" are accepted. The spelling diagnostic runs once,
        // debug-only, so a flood of unsigned requests cannot spam the log file.
        $signature = $request->header('Paymongo-Signature')
            ?? $request->header('X-Paymongo-Signature');

        if (config('app.debug')) {
            Log::channel('paymongo')->info('PayMongo webhook received', [
                'header_spelling' => $request->header('Paymongo-Signature') !== null
                    ? 'Paymongo-Signature'
                    : 'X-Paymongo-Signature',
            ]);
        }

        if (! $payMongo->verifyWebhookSignature($rawBody, $signature, (bool) config('services.paymongo.livemode'))) {
            Log::channel('paymongo')->warning('PayMongo webhook rejected: signature verification failed');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = json_decode($rawBody, true);
        $type = $payload['data']['attributes']['type'] ?? null;
        $livemode = $payload['data']['attributes']['livemode'] ?? null;

        if ($type === null || ! is_bool($livemode)) {
            Log::channel('paymongo')->warning('PayMongo webhook skipped: malformed payload', [
                'event_type' => $type,
            ]);

            return $this->acknowledge();
        }

        if ($livemode !== (bool) config('services.paymongo.livemode')) {
            Log::channel('paymongo')->warning('PayMongo webhook skipped: livemode mismatch', [
                'event_type' => $type,
                'event_livemode' => $livemode,
            ]);

            return $this->acknowledge();
        }

        if (! in_array($type, self::KNOWN_EVENT_TYPES, true)) {
            Log::channel('paymongo')->info('PayMongo webhook acknowledged: unknown event type', [
                'event_type' => $type,
            ]);

            return $this->acknowledge();
        }

        // Known event type — queue it. The job dedupes by event id, marks the
        // invoice paid on payment.paid, and logs-only on the other types. The
        // ack is still returned immediately (well within the 30s window).
        Log::channel('paymongo')->info('PayMongo webhook acknowledged: known event type', [
            'event_id' => $payload['data']['id'] ?? null,
            'event_type' => $type,
        ]);

        ProcessPayMongoWebhook::dispatch($payload);

        return $this->acknowledge();
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json(['received' => true]);
    }
}
