<?php

namespace App\Services;

use App\Exceptions\InvoiceNotPayableException;
use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PayMongoService
{
    public const API_BASE = 'https://api.paymongo.com/v1';

    /**
     * Valid values per PayMongo docs (docs.paymongo.com/reference/create-a-paymentintent).
     */
    private const VALID_PAYMENT_METHODS = [
        'qrph', 'brankas', 'card', 'dob', 'billease',
        'gcash', 'grab_pay', 'shopee_pay', 'paymaya',
    ];

    private const TIMEOUT_SECONDS = 15;

    private const MAX_ATTEMPTS = 3;

    private const RETRY_SLEEP_MICROSECONDS = 100_000;

    public function getOrCreatePaymentIntent(Invoice $invoice): array
    {
        return DB::transaction(function () use ($invoice): array {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($locked->status, ['unpaid', 'overdue'], true)) {
                throw new InvoiceNotPayableException($locked);
            }

            if ($locked->paymongo_payment_intent_id) {
                return $this->getPaymentIntent($locked->paymongo_payment_intent_id, $locked);
            }

            return $this->createPaymentIntent($locked);
        });
    }

    public function createPaymentIntent(Invoice $invoice, array $methods = ['qrph', 'gcash', 'card']): array
    {
        $methods = $this->validateMethods($methods);
        $amount = $this->toCentavos($invoice->total_amount);

        $response = $this->sendWithRetry(fn (): Response => $this->request()
            ->withHeaders(['Idempotency-Key' => 'invoice-pay-'.$invoice->id])
            ->post(self::API_BASE.'/payment_intents', [
                'data' => [
                    'attributes' => [
                        'amount' => $amount,
                        'currency' => 'PHP',
                        'payment_method_allowed' => $methods,
                        'description' => 'Invoice '.$invoice->invoice_number,
                        'metadata' => [
                            'invoice_id' => (string) $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                        ],
                    ],
                ],
            ]));

        if ($response->failed()) {
            Log::channel('paymongo')->error('PayMongo create payment intent failed', [
                'invoice_id' => $invoice->id,
                'response_body' => $response->body(),
            ]);

            throw new RuntimeException('PayMongo create payment intent failed: '.$response->body());
        }

        $intentId = $response->json('data.id');
        $clientKey = $response->json('data.attributes.client_key');

        if (! is_string($intentId) || ! is_string($clientKey)) {
            throw new RuntimeException('PayMongo response missing payment intent id or client key.');
        }

        $invoice->update(['paymongo_payment_intent_id' => $intentId]);

        Log::channel('paymongo')->info('PayMongo payment intent created', [
            'invoice_id' => $invoice->id,
            'intent_id' => $intentId,
            'amount_centavos' => $amount,
            'methods' => $methods,
        ]);

        return [
            'intent_id' => $intentId,
            'client_key' => $clientKey,
        ];
    }

    public function getPaymentIntent(string $intentId, ?Invoice $invoice = null): array
    {
        $response = $this->sendWithRetry(fn (): Response => $this->request()
            ->get(self::API_BASE.'/payment_intents/'.$intentId));

        if ($response->failed()) {
            Log::channel('paymongo')->error('PayMongo retrieve payment intent failed', [
                'intent_id' => $intentId,
                'response_body' => $response->body(),
            ]);

            throw new RuntimeException('PayMongo retrieve payment intent failed: '.$response->body());
        }

        $clientKey = $response->json('data.attributes.client_key');

        if (! is_string($clientKey)) {
            throw new RuntimeException('PayMongo response missing client key.');
        }

        if ($invoice !== null && ! $this->belongsToInvoice($response->json('data.attributes.metadata'), $invoice)) {
            throw new RuntimeException(sprintf(
                'PayMongo intent %s does not belong to invoice %s.',
                $intentId,
                $invoice->id
            ));
        }

        return [
            'intent_id' => $intentId,
            'client_key' => $clientKey,
        ];
    }

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth($this->secretKey(), '')
            ->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Sends a request, retrying on 5xx responses and transient connection errors.
     * Safe to retry: POST requests always carry an Idempotency-Key and GET is
     * read-only. After the final attempt a failed response is returned as-is
     * (callers inspect $response->failed()) and a ConnectionException is rethrown.
     * NOTE: the Laravel HTTP client's built-in retry() is not used because it
     * throws RequestException after retries are exhausted, breaking this service's
     * RuntimeException (API error) / ConnectionException (network) contract.
     */
    protected function sendWithRetry(callable $request): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request();
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                usleep(self::RETRY_SLEEP_MICROSECONDS);

                continue;
            }

            if (! $response->serverError() || $attempt >= self::MAX_ATTEMPTS) {
                return $response;
            }

            usleep(self::RETRY_SLEEP_MICROSECONDS);
        }
    }

    protected function belongsToInvoice(?array $metadata, Invoice $invoice): bool
    {
        return isset($metadata['invoice_id'])
            && (string) $metadata['invoice_id'] === (string) $invoice->id;
    }

    protected function validateMethods(array $methods): array
    {
        $methods = array_values(array_unique($methods));

        if ($methods === []) {
            throw new InvalidArgumentException('At least one payment method is required.');
        }

        $invalid = array_diff($methods, self::VALID_PAYMENT_METHODS);

        if ($invalid !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported payment method(s): %s. Allowed: %s.',
                implode(', ', $invalid),
                implode(', ', self::VALID_PAYMENT_METHODS)
            ));
        }

        return $methods;
    }

    /**
     * Verifies a webhook request signature per PayMongo's documented scheme
     * (docs.paymongo.com/docs/developer-tools-webhook-setup-management).
     *
     * The Paymongo-Signature header holds three comma-separated parts:
     *   t=<unix timestamp>,te=<test-mode sig>,li=<live-mode sig>
     * The signed string is "<t>.<raw body>"; the digest is HMAC-SHA256 with
     * the endpoint's webhook secret, HEX-encoded. Compare against te for
     * test-mode events, li for live-mode events.
     *
     * NOTE: two earlier implementations (base64 digest of the body, then hex
     * digest of the body alone) rejected every real delivery with 401 — the
     * missing pieces were the timestamp prefix and the te/li selection. The
     * unit tests passed because the test helper signed in the same wrong
     * format; only a real delivery exposed it. Fails closed (returns false)
     * when the secret, signature, timestamp, or the selected part is
     * missing/empty.
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signature, bool $isLivemode = false): bool
    {
        $secret = config('services.paymongo.webhook_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || $signature === '') {
            Log::channel('paymongo')->warning('PayMongo webhook verification skipped', [
                'signature_present' => is_string($signature) && $signature !== '',
                'secret_configured' => is_string($secret) && $secret !== '',
            ]);

            return false;
        }

        $parts = [];

        foreach (explode(',', $signature) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $timestamp = $parts['t'] ?? '';
        $expected = $isLivemode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');

        if ($timestamp === '' || $expected === '') {
            Log::channel('paymongo')->warning('PayMongo webhook verification skipped: malformed signature parts', [
                'timestamp_present' => $timestamp !== '',
                'selected_part_present' => $expected !== '',
                'livemode' => $isLivemode,
            ]);

            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($computed, $expected);
    }

    protected function secretKey(): string
    {
        $key = config('services.paymongo.secret_key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('PAYMONGO_SECRET_KEY is not configured.');
        }

        return $key;
    }

    protected function toCentavos(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
