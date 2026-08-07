<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitTest extends TestCase
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

    private function webhookSignatureFor(string $rawBody): string
    {
        $digest = hash_hmac('sha256', self::SIGNATURE_TIMESTAMP.'.'.$rawBody, self::SECRET);

        return 't='.self::SIGNATURE_TIMESTAMP.',te='.$digest.',li=';
    }

    private function paymentPaidRawBody(): string
    {
        return json_encode([
            'data' => [
                'id' => 'evt_'.uniqid(),
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => 'pay_'.uniqid(),
                        'type' => 'payment',
                        'attributes' => [
                            'amount' => 4000,
                            'currency' => 'PHP',
                            'status' => 'paid',
                            'payment_intent_id' => 'pi_'.uniqid(),
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_webhook_route_accepts_traffic_up_to_the_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.91']);

        for ($i = 0; $i < 60; $i++) {
            $rawBody = $this->paymentPaidRawBody();

            $this->call('POST', '/api/paymongo/webhook', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
            ], $rawBody)->assertOk();
        }
    }

    public function test_webhook_route_is_throttled_per_ip_after_the_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.92']);

        for ($i = 0; $i < 60; $i++) {
            $rawBody = $this->paymentPaidRawBody();

            $this->call('POST', '/api/paymongo/webhook', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
            ], $rawBody)->assertOk();
        }

        $rawBody = $this->paymentPaidRawBody();

        $response = $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
        ], $rawBody);

        $response->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After');
    }

    public function test_webhook_rate_limit_buckets_are_separate_per_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.93']);

        for ($i = 0; $i < 60; $i++) {
            $rawBody = $this->paymentPaidRawBody();

            $this->call('POST', '/api/paymongo/webhook', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
            ], $rawBody)->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.94']);

        $rawBody = $this->paymentPaidRawBody();

        $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
        ], $rawBody)->assertOk();
    }

    public function test_links_list_is_throttled_per_user_after_thirty_requests(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/links')->assertOk();
        }

        $this->getJson('/api/links')
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.'])
            ->assertHeader('X-RateLimit-Limit', '30');
    }

    public function test_rate_limit_buckets_are_isolated_per_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Sanctum::actingAs($userA);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/links')->assertOk();
        }

        $this->getJson('/api/links')->assertStatus(429);

        Sanctum::actingAs($userB);

        $this->getJson('/api/links')->assertOk();
    }
}
