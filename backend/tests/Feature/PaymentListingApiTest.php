<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentListingApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLinkedUser(): array
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-PAY-001',
            'meter_number' => 'MTR-PAY-001',
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

    private function makePaidPayment(ServiceConnection $connection, array $overrides = []): Payment
    {
        $invoice = Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'status' => 'paid',
            'invoice_number' => 'GW-PAID-'.fake()->unique()->numberBetween(1000, 9999),
        ]);

        return Payment::factory()->create(array_merge([
            'invoice_id' => $invoice->id,
            'method' => 'paymongo',
            'paymongo_reference' => 'pay_'.fake()->uuid(),
            'reference' => null,
            'paid_at' => now(),
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makePaidPayment($connection);

        $this->getJson('/api/payments')->assertStatus(401);
    }

    public function test_returns_recent_payments_newest_first_with_invoice_details(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $old = $this->makePaidPayment($connection, [
            'amount' => 100.00,
            'paid_at' => now()->subDays(5),
        ]);
        $recent = $this->makePaidPayment($connection, [
            'amount' => 250.00,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('*.id', [$recent->id, $old->id])
            ->assertJsonPath('0.amount', 250)
            ->assertJsonPath('0.invoice_number', $recent->invoice->invoice_number)
            ->assertJsonPath('0.method', 'paymongo')
            ->assertJsonPath('0.service_connection.account_number', 'GW-PAY-001')
            ->assertJsonPath('0.service_connection.barangay', 'Poblacion');
    }

    public function test_excludes_payments_of_connections_not_linked_to_the_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makePaidPayment($connection);

        $strangerConnection = ServiceConnection::factory()->create();
        $this->makePaidPayment($strangerConnection);

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_excludes_payments_behind_revoked_links(): void
    {
        $connection = ServiceConnection::factory()->create();
        $this->makePaidPayment($connection);

        $user = User::factory()->create();
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'revoked',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_returns_empty_array_when_user_has_no_payments(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_limits_to_the_ten_most_recent(): void
    {
        [$user, $connection] = $this->makeLinkedUser();

        for ($i = 0; $i < 12; $i++) {
            $this->makePaidPayment($connection, ['paid_at' => now()->subMinutes(12 - $i)]);
        }

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonCount(10);
    }

    public function test_carries_the_paymongo_channel(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makePaidPayment($connection, [
            'method' => 'paymongo',
            'paymongo_source' => 'gcash',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/payments')
            ->assertOk()
            ->assertJsonPath('0.channel', 'gcash');
    }
}
