<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\Barangay;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DevPaymentSimulationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeLinkedUser(): array
    {
        $barangay = Barangay::factory()->create(['name' => 'Poblacion']);
        $connection = ServiceConnection::factory()->create([
            'account_number' => 'GW-DEV-001',
            'meter_number' => 'MTR-DEV-001',
            'registered_name' => 'Dev Tester',
            'barangay_id' => $barangay->id,
        ]);
        $user = User::factory()->create(['email' => 'dev@example.com']);

        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $connection->id,
            'status' => 'active',
        ]);

        return [$user, $connection];
    }

    public function test_marks_an_owned_unpaid_invoice_paid_and_returns_ids(): void
    {
        Queue::fake();
        [$user, $connection] = $this->makeLinkedUser();
        // Simulate the reported laptop case: the invoice still holds a REAL
        // leftover intent from an earlier flow (an expired QR Ph code). The
        // Google Pay simulation must fabricate its own intent, never reuse it.
        $invoice = Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'status' => 'unpaid',
            'invoice_number' => 'GW-DEV-UNPAID-001',
            'total_amount' => 500.00,
            'paymongo_payment_intent_id' => 'pi_real_qrph_leftover',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/dev/payments/simulate', ['invoice_id' => $invoice->id]);

        $response->assertOk()
            ->assertJsonPath('source', 'google_pay_card')
            ->assertJsonStructure(['payment_id', 'event_id']);

        $this->assertSame('paid', $invoice->fresh()->status);

        // Fresh simulated intent — the QR Ph leftover was replaced, so the
        // simulated payment can never be attributed to the QR Ph flow.
        $this->assertStringStartsWith('pi_sim_', $invoice->fresh()->paymongo_payment_intent_id);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('paymongo', $payment->method);
        $this->assertSame('google_pay_card', $payment->paymongo_source);
        $this->assertStringStartsWith('pay_sim_', $payment->paymongo_reference);

        Queue::assertPushed(SendPaymentConfirmationEmail::class);
    }

    public function test_forbids_an_invoice_of_an_unlinked_connection(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $other = ServiceConnection::factory()->create();
        $invoice = Invoice::factory()->create([
            'service_connection_id' => $other->id,
            'status' => 'unpaid',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/dev/payments/simulate', ['invoice_id' => $invoice->id])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Forbidden');

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_conflicts_on_an_already_paid_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        $invoice = Invoice::factory()->create([
            'service_connection_id' => $connection->id,
            'status' => 'paid',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/dev/payments/simulate', ['invoice_id' => $invoice->id])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Invoice is already paid.');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_returns_404_for_an_unknown_invoice(): void
    {
        [$user, $connection] = $this->makeLinkedUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/dev/payments/simulate', ['invoice_id' => 999_999])
            ->assertStatus(404);
    }

    public function test_endpoint_is_absent_in_production(): void
    {
        $user = User::factory()->create();

        putenv('APP_ENV=production');
        $this->refreshApplication();
        Sanctum::actingAs($user);

        $this->postJson('/api/dev/payments/simulate', ['invoice_id' => 1])
            ->assertStatus(404);

        putenv('APP_ENV=testing');
        $this->refreshApplication();
    }
}