<?php

namespace Tests\Feature;

use App\Models\ConnectionLink;
use App\Models\ServiceConnection;
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
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Reset');
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

    public function test_webhook_calls_do_not_consume_the_login_bucket_for_the_same_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.21']);

        for ($i = 0; $i < 10; $i++) {
            $rawBody = $this->paymentPaidRawBody();

            $this->call('POST', '/api/paymongo/webhook', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
            ], $rawBody)->assertOk();
        }

        $this->postJson('/api/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_calls_do_not_consume_the_webhook_bucket_for_the_same_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.22']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $rawBody = $this->paymentPaidRawBody();

        $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
        ], $rawBody)->assertOk();
    }

    public function test_user_endpoint_is_throttled_per_user_after_thirty_requests(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/user')->assertOk();
        }

        $this->getJson('/api/user')
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.']);
    }

    public function test_logout_endpoint_is_throttled_per_user_after_thirty_requests(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/logout')->assertOk();
        }

        $this->postJson('/api/logout')
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.']);
    }

    public function test_links_store_is_throttled_per_user_after_thirty_requests(): void
    {
        $user = User::factory()->create();
        $connection = ServiceConnection::factory()->create([
            'status' => 'active',
            'account_number' => 'GW-RL-STR',
            'meter_number' => 'MTR-RL-STR',
        ]);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/links', [
                'account_number' => $connection->account_number,
                'meter_number' => $connection->meter_number,
            ])->assertCreated();
        }

        $this->postJson('/api/links', [
            'account_number' => $connection->account_number,
            'meter_number' => $connection->meter_number,
        ])->assertStatus(429);
    }

    public function test_links_destroy_is_throttled_per_user_after_thirty_requests(): void
    {
        $user = User::factory()->create();
        $link = ConnectionLink::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->deleteJson("/api/links/{$link->id}")->assertOk();
        }

        $this->deleteJson("/api/links/{$link->id}")
            ->assertStatus(429)
            ->assertJson(['message' => 'Too Many Attempts.']);
    }

    public function test_authenticated_buckets_are_keyed_by_user_not_client_ip(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.7.1']);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/user')->assertOk();
        }

        $this->getJson('/api/user')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.7.2']);

        $this->getJson('/api/user')->assertStatus(429);
    }

    public function test_webhook_bucket_resets_after_the_decay_window(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.31']);

        for ($i = 0; $i < 60; $i++) {
            $rawBody = $this->paymentPaidRawBody();

            $this->call('POST', '/api/paymongo/webhook', [], [], [], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
            ], $rawBody)->assertOk();
        }

        $rawBody = $this->paymentPaidRawBody();

        $this->call('POST', '/api/paymongo/webhook', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $this->webhookSignatureFor($rawBody),
        ], $rawBody)->assertStatus(429);

        $this->travel(61)->seconds();

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
