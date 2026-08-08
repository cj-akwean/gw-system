<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentIntentStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.paymongo.secret_key', 'sk_test_dummy_secret');
    }

    private function makeLinkedUser(): array
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-INT-001',
            'meter_number' => 'MTR-INT-001',
            'registered_name' => 'Maria Santos',
            'barangay_id' => $barangay->id,
        ]);
        $user = User::factory()->create(['email' => 'customer@example.com']);

        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
        ]);

        return [$user, $connection];
    }

    private function makeInvoice(ServiceConnection $connection, array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge([
            'service_connection_id' => $connection->id,
            'status' => 'unpaid',
            'paymongo_payment_intent_id' => 'pi_test_status_1',
        ], $overrides));
    }

    private function fakeIntentStatus(string $status): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_status_1' => Http::response([
                'data' => [
                    'id' => 'pi_test_status_1',
                    'attributes' => ['status' => $status],
                ],
            ]),
        ]);
    }

    public function test_intent_status_requires_authentication(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertStatus(401);
    }

    public function test_intent_status_requires_payment_intent_id(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_intent_status_returns_unknown_for_an_intent_with_no_local_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_unknown_to_us',
        ])->assertOk()
            ->assertJson(['status' => 'unknown']);

        Http::assertNothingSent();
    }

    public function test_intent_status_forbids_an_intent_on_an_unlinked_connection(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertStatus(403)
            ->assertJson(['message' => 'Forbidden']);

        Http::assertNothingSent();
    }

    public function test_intent_status_returns_paid_when_the_invoice_is_already_credited(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, ['status' => 'paid']);

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson([
                'status' => 'paid',
                'invoice_id' => $invoice->id,
            ]);

        Http::assertNothingSent();
    }

    public function test_intent_status_returns_confirmed_when_paymongo_says_succeeded_but_webhook_has_not_credited(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakeIntentStatus('succeeded');

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson([
                'status' => 'confirmed',
                'invoice_id' => $invoice->id,
            ]);
    }

    public function test_intent_status_returns_failed_when_paymongo_reports_failed(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakeIntentStatus('failed');

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson([
                'status' => 'failed',
                'invoice_id' => $invoice->id,
            ]);
    }

    public function test_intent_status_returns_failed_when_the_payment_did_not_complete(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakeIntentStatus('awaiting_payment_method');

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson([
                'status' => 'failed',
                'invoice_id' => $invoice->id,
            ]);
    }

    public function test_intent_status_returns_processing_while_the_payment_is_in_flight(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakeIntentStatus('processing');

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson([
                'status' => 'processing',
                'invoice_id' => $invoice->id,
            ]);
    }

    public function test_intent_status_returns_unknown_when_paymongo_no_longer_knows_the_intent(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_status_1' => Http::response(['errors' => []], 404),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertOk()
            ->assertJson(['status' => 'unknown']);
    }

    public function test_intent_status_returns_502_when_paymongo_is_unavailable(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_status_1' => Http::response(['errors' => []], 500),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/payments/intent-status', [
            'payment_intent_id' => 'pi_test_status_1',
        ])->assertStatus(502)
            ->assertJson(['message' => 'Payment gateway unavailable. Please try again.']);
    }
}
