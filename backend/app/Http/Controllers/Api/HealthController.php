<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    /**
     * Checks if the PayMongo payment system is healthy: API key configured,
     * webhook secret configured, and PayMongo API reachable.
     *
     * Cached for 60 seconds to avoid rate-limit pressure on page loads.
     */
    public function check(): JsonResponse
    {
        $result = Cache::remember('paymongo:health', 60, function (): array {
            $secretKey = config('services.paymongo.secret_key');
            $webhookSecret = config('services.paymongo.webhook_secret');

            if (! is_string($secretKey) || $secretKey === '') {
                return ['healthy' => false, 'reason' => 'Payment system not configured.'];
            }

            if (! is_string($webhookSecret) || $webhookSecret === '') {
                return ['healthy' => false, 'reason' => 'Webhook not configured.'];
            }

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Basic '.base64_encode($secretKey.':'),
                    'Accept' => 'application/json',
                ])
                    ->timeout(10)
                    ->get('https://api.paymongo.com/v1/payments', ['limit' => 1]);

                if ($response->successful()) {
                    return ['healthy' => true];
                }

                return ['healthy' => false, 'reason' => 'Payment gateway returned an error.'];
            } catch (\Exception $e) {
                return ['healthy' => false, 'reason' => 'Payment gateway unreachable.'];
            }
        });

        $status = $result['healthy'] ? 200 : 503;

        return response()->json($result, $status);
    }
}
