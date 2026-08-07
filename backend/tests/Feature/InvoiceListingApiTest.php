<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceListingApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeLinkedUser(): array
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-LIST-001',
            'meter_number' => 'MTR-LIST-001',
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
        ], $overrides));
    }

    public function test_index_requires_authentication(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        $this->getJson('/api/invoices')->assertStatus(401);
    }

    public function test_returns_only_unpaid_and_overdue_invoices(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection, ['invoice_number' => 'GW-0001', 'status' => 'unpaid']);
        $this->makeInvoice($connection, ['invoice_number' => 'GW-0002', 'status' => 'overdue']);
        $this->makeInvoice($connection, ['invoice_number' => 'GW-0003', 'status' => 'paid']);
        $this->makeInvoice($connection, ['invoice_number' => 'GW-0004', 'status' => 'cancelled']);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJson(['0' => ['status' => 'overdue', 'invoice_number' => 'GW-0002']])
            ->assertJson(['1' => ['status' => 'unpaid', 'invoice_number' => 'GW-0001']])
            ->assertJsonMissing(['invoice_number' => 'GW-0003'])
            ->assertJsonMissing(['invoice_number' => 'GW-0004']);
    }

    public function test_excludes_invoices_of_connections_not_linked_to_the_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection, ['invoice_number' => 'GW-LINKED']);

        $strangerConnection = ServiceConnection::factory()->create();
        $this->makeInvoice($strangerConnection, ['invoice_number' => 'GW-STRANGER']);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonMissing(['invoice_number' => 'GW-STRANGER']);
    }

    public function test_excludes_invoices_behind_revoked_links(): void
    {
        $barangay = Barangay::factory()->create();
        $connection = ServiceConnection::factory()->create(['barangay_id' => $barangay->id]);
        $this->makeInvoice($connection, ['invoice_number' => 'GW-REVOKED']);

        $user = User::factory()->create();
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'revoked',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_returns_connection_details_with_each_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection, ['invoice_number' => 'GW-DETAIL']);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJson(['0' => [
                'service_connection' => [
                    'account_number' => 'GW-LIST-001',
                    'meter_number' => 'MTR-LIST-001',
                    'registered_name' => 'Maria Santos',
                    'barangay' => 'Poblacion',
                ],
            ]]);
    }

    public function test_includes_billing_period_due_date_and_amounts(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection, [
            'invoice_number' => 'GW-AMT',
            'billing_period_start' => '2026-07-01',
            'billing_period_end' => '2026-07-31',
            'due_date' => '2026-08-15',
            'previous_balance' => 50.00,
            'base_amount' => 150.00,
            'penalty_amount' => 5.00,
            'total_amount' => 205.00,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJson(['0' => [
                'billing_period_start' => '2026-07-01',
                'billing_period_end' => '2026-07-31',
                'due_date' => '2026-08-15',
                'previous_balance' => 50.0,
                'base_amount' => 150.0,
                'penalty_amount' => 5.0,
                'total_amount' => 205.0,
            ]]);
    }

    public function test_orders_overdue_first_then_by_due_date(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $latePaid = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-UNPAID-FAR',
            'status' => 'unpaid',
            'due_date' => '2026-09-15',
        ]);
        $overdueEarly = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-OVERDUE-EARLY',
            'status' => 'overdue',
            'due_date' => '2026-07-01',
        ]);
        $overdueLate = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-OVERDUE-LATE',
            'status' => 'overdue',
            'due_date' => '2026-08-01',
        ]);
        $unpaidEarly = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-UNPAID-EARLY',
            'status' => 'unpaid',
            'due_date' => '2026-08-15',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonPath('*.id', [
                $overdueEarly->id,
                $overdueLate->id,
                $unpaidEarly->id,
                $latePaid->id,
            ]);
    }

    public function test_returns_empty_array_when_user_has_no_bills(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJson([]);
    }

    public function test_aggregates_across_multiple_linked_connections(): void
    {
        $user = User::factory()->create();
        $barangay = Barangay::factory()->create();
        $connectionA = ServiceConnection::factory()->create(['barangay_id' => $barangay->id]);
        $connectionB = ServiceConnection::factory()->create(['barangay_id' => $barangay->id]);

        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connectionA->id,
            'status' => 'active',
        ]);
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connectionB->id,
            'status' => 'active',
        ]);

        $this->makeInvoice($connectionA, ['invoice_number' => 'GW-A']);
        $this->makeInvoice($connectionB, ['invoice_number' => 'GW-B']);

        Sanctum::actingAs($user);

        $this->getJson('/api/invoices')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_index_is_rate_limited(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $this->makeInvoice($connection);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/invoices')->assertOk();
        }

        $this->getJson('/api/invoices')->assertStatus(429);
    }

    public function test_show_requires_authentication(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection);

        $this->getJson("/api/invoices/{$invoice->id}")->assertStatus(401);
    }

    public function test_show_rejects_an_invoice_not_linked_to_the_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $strangerConnection = ServiceConnection::factory()->create();
        $invoice = $this->makeInvoice($strangerConnection);

        Sanctum::actingAs($user);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden']);
    }

    public function test_show_returns_a_paid_invoice_to_a_linked_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-PAID',
            'status' => 'paid',
            'total_amount' => 205.00,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson([
                'id' => $invoice->id,
                'invoice_number' => 'GW-PAID',
                'status' => 'paid',
                'total_amount' => 205.0,
                'service_connection' => [
                    'account_number' => 'GW-LIST-001',
                    'meter_number' => 'MTR-LIST-001',
                ],
            ]);
    }

    public function test_show_returns_an_unpaid_invoice_to_a_linked_user(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = $this->makeInvoice($connection, [
            'invoice_number' => 'GW-UNPAID',
            'status' => 'overdue',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJson([
                'id' => $invoice->id,
                'invoice_number' => 'GW-UNPAID',
                'status' => 'overdue',
            ]);
    }
}