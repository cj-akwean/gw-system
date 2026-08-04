<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoicePaymentEndpointTest extends TestCase
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
            'account_number' => 'GW-00001',
            'meter_number' => 'MTR-00001',
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
            'total_amount' => 2020.00,
        ], $overrides));
    }

    private function fakePayMongoIntent(Invoice $invoice): void
    {
        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_1',
                    'attributes' => ['client_key' => 'pi_test_intent_1_client_key'],
                ],
            ]),
            'api.paymongo.com/v1/payment_intents/*' => Http::response([
                'data' => [
                    'id' => 'pi_test_intent_1',
                    'attributes' => [
                        'client_key' => 'pi_test_intent_1_client_key',
                        'metadata' => ['invoice_id' => (string) $invoice->id],
                    ],
                ],
            ]),
        ]);
    }

    public function test_pay_requires_authentication(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(401);
    }

    public function test_pay_rejects_invoice_not_linked_to_the_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);

        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden']);
    }

    public function test_pay_rejects_an_already_paid_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, ['status' => 'paid']);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(409)
            ->assertJson(['message' => 'Invoice is already paid.']);

        Http::assertNothingSent();
    }

    public function test_pay_rejects_a_cancelled_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, ['status' => 'cancelled']);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(409)
            ->assertJson(['message' => 'Invoice is not payable.']);

        Http::assertNothingSent();
    }

    public function test_pay_creates_intent_and_returns_client_key(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakePayMongoIntent($invoice);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertOk()
            ->assertJson([
                'client_key' => 'pi_test_intent_1_client_key',
                'payment_intent_id' => 'pi_test_intent_1',
            ]);

        $this->assertSame('pi_test_intent_1', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_pay_allows_an_overdue_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, ['status' => 'overdue']);
        $this->fakePayMongoIntent($invoice);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertOk()
            ->assertJson(['payment_intent_id' => 'pi_test_intent_1']);
    }

    public function test_pay_reuses_an_existing_intent_instead_of_creating_a_new_one(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, ['paymongo_payment_intent_id' => 'pi_test_existing']);

        Http::fake([
            'api.paymongo.com/v1/payment_intents/pi_test_existing' => Http::response([
                'data' => [
                    'id' => 'pi_test_existing',
                    'attributes' => [
                        'client_key' => 'pi_test_existing_client_key',
                        'metadata' => ['invoice_id' => (string) $invoice->id],
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertOk()
            ->assertJson([
                'client_key' => 'pi_test_existing_client_key',
                'payment_intent_id' => 'pi_test_existing',
            ]);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), '/payment_intents/pi_test_existing'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_pay_returns_502_when_paymongo_is_unavailable(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);

        Http::fake([
            'api.paymongo.com/v1/payment_intents' => Http::response(['errors' => []], 500),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(502)
            ->assertJson(['message' => 'Payment gateway unavailable. Please try again.']);
    }

    public function test_pay_route_is_rate_limited(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);
        $this->fakePayMongoIntent($invoice);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/invoices/{$invoice->id}/pay")->assertOk();
        }

        $this->postJson("/api/invoices/{$invoice->id}/pay")
            ->assertStatus(429);
    }
}
