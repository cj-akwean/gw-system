<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PayMongoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private const SIGNATURE_TIMESTAMP = '1496734173';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paymongo.webhook_secret', self::SECRET);
        config()->set('services.paymongo.livemode', false);
    }

    /**
     * Builds a real PayMongo signature header: t=<timestamp>,te=<hex of
     * HMAC-SHA256("<t>.<body>", secret)>,li=<same for live mode>.
     */
    private function signatureFor(string $rawBody, bool $livemode = false): string
    {
        $digest = hash_hmac('sha256', self::SIGNATURE_TIMESTAMP.'.'.$rawBody, self::SECRET);

        return 't='.self::SIGNATURE_TIMESTAMP
            .',te='.($livemode ? '' : $digest)
            .',li='.($livemode ? $digest : '');
    }

    private function paymentPaidPayload(array $overrides = []): array
    {
        return array_merge_recursive([
            'data' => [
                'id' => 'evt_webhook_test_1',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_test_1',
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_test_1',
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }

    private function postRaw(string $rawBody, ?string $signature): TestResponse
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_PAYMONGO_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/paymongo/webhook', [], [], [], $server, $rawBody);
    }

    public function test_webhook_rejects_a_request_without_a_signature_header(): void
    {
        $this->postRaw(json_encode($this->paymentPaidPayload()), null)
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid signature.']);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->postRaw(
            json_encode($this->paymentPaidPayload()),
            'injected_signature'
        )->assertStatus(401);
    }

    public function test_webhook_rejects_when_the_secret_is_not_configured(): void
    {
        config()->set('services.paymongo.webhook_secret', null);

        $rawBody = json_encode($this->paymentPaidPayload());

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertStatus(401);
    }

    public function test_webhook_acknowledges_an_unknown_event_type_without_processing(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload([
            'data' => ['attributes' => ['type' => 'dispute.created']],
        ]));

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_acknowledges_but_skips_a_livemode_mismatch(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload([
            'data' => ['attributes' => ['livemode' => true]],
        ]));

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_acknowledges_a_known_event_with_matching_livemode(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_acknowledges_malformed_json_with_a_valid_signature(): void
    {
        $rawBody = '{"data":{"attributes":{"type":"payment.paid"';

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_rejects_a_get_request(): void
    {
        $this->getJson('/api/paymongo/webhook')->assertStatus(405);
    }

    public function test_webhook_signature_is_verified_against_the_raw_body_bytes(): void
    {
        $rawBody = '{"data":{"id":"evt_unicode_1","type":"event","attributes":{"type":"payment.paid","livemode":false,"data":{"id":"pay_1","type":"payment","attributes":{"amount":4000,"currency":"PHP","status":"paid","payment_intent_id":"pi_1","metadata":{"customer":"Maria — água ✓"}}}}}}';

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_accepts_the_x_paymongo_signature_header_fallback(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());
        $signature = $this->signatureFor($rawBody);

        $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYMONGO_SIGNATURE' => $signature,
        ], $rawBody)->assertOk();
    }

    public function test_webhook_does_not_require_authentication(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertOk();
    }

    public function test_webhook_rejects_a_body_only_hmac_signature(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $header = 't='.self::SIGNATURE_TIMESTAMP
            .',te='.hash_hmac('sha256', $rawBody, self::SECRET)
            .',li=';

        $this->postRaw($rawBody, $header)->assertStatus(401);
    }

    public function test_webhook_rejects_a_base64_digest_signature(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $header = 't='.self::SIGNATURE_TIMESTAMP
            .',te='.base64_encode(hash_hmac(
                'sha256',
                self::SIGNATURE_TIMESTAMP.'.'.$rawBody,
                self::SECRET,
                true
            ))
            .',li=';

        $this->postRaw($rawBody, $header)->assertStatus(401);
    }

    public function test_webhook_rejects_a_signature_with_a_mismatched_timestamp(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());
        $digest = hash_hmac('sha256', '1496734174.'.$rawBody, self::SECRET);

        $this->postRaw($rawBody, 't=1496734173,te='.$digest.',li=')
            ->assertStatus(401);
    }

    public function test_webhook_rejects_a_header_without_a_timestamp_part(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $header = 'te='.hash_hmac('sha256', self::SIGNATURE_TIMESTAMP.'.'.$rawBody, self::SECRET).',li=';

        $this->postRaw($rawBody, $header)->assertStatus(401);
    }

    public function test_webhook_rejects_a_header_without_the_test_signature_part(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());

        $this->postRaw($rawBody, 't='.self::SIGNATURE_TIMESTAMP.',li=')
            ->assertStatus(401);
    }

    public function test_webhook_accepts_a_signature_with_whitespace_around_parts(): void
    {
        $rawBody = json_encode($this->paymentPaidPayload());
        $digest = hash_hmac('sha256', self::SIGNATURE_TIMESTAMP.'.'.$rawBody, self::SECRET);

        $this->postRaw($rawBody, ' t=1496734173 , te='.$digest.' , li= ')
            ->assertOk();
    }

    public function test_webhook_uses_the_live_signature_part_when_configured_for_live_mode(): void
    {
        config()->set('services.paymongo.livemode', true);

        $rawBody = json_encode($this->paymentPaidPayload([
            'data' => ['attributes' => ['livemode' => true]],
        ]));

        $this->postRaw($rawBody, $this->signatureFor($rawBody, true))
            ->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_rejects_a_test_signature_when_configured_for_live_mode(): void
    {
        config()->set('services.paymongo.livemode', true);

        $rawBody = json_encode($this->paymentPaidPayload());

        $this->postRaw($rawBody, $this->signatureFor($rawBody))
            ->assertStatus(401);
    }
}
