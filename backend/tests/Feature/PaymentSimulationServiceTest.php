<?php

namespace Tests\Feature;

use App\Exceptions\InvoiceNotPayableException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentSimulationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSimulationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fabricates_and_persists_an_intent_id_when_missing(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);
        $this->assertNull($invoice->paymongo_payment_intent_id);

        app(PaymentSimulationService::class)->simulate($invoice, 'google_pay_card');

        $this->assertStringStartsWith('pi_sim_', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_reuses_an_existing_stored_intent(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'unpaid',
            'paymongo_payment_intent_id' => 'pi_real_456',
        ]);

        app(PaymentSimulationService::class)->simulate($invoice, 'google_pay_card');

        $this->assertSame('pi_real_456', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_force_fresh_intent_never_reuses_a_stored_intent(): void
    {
        // A leftover real intent (e.g. an expired QR Ph code) must not leak
        // into a simulated Google Pay payment — the harness always fabricates
        // its own pi_sim_ intent.
        $invoice = Invoice::factory()->create([
            'status' => 'unpaid',
            'paymongo_payment_intent_id' => 'pi_real_qrph_leftover',
        ]);

        app(PaymentSimulationService::class)->simulate($invoice, 'google_pay_card', null, true);

        $this->assertStringStartsWith('pi_sim_', $invoice->fresh()->paymongo_payment_intent_id);
        $this->assertNotSame('pi_real_qrph_leftover', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_records_the_source_channel(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);

        app(PaymentSimulationService::class)->simulate($invoice, 'google_pay_card');

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('google_pay_card', $payment->paymongo_source);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_throws_for_a_non_payable_invoice(): void
    {
        $this->expectException(InvoiceNotPayableException::class);

        app(PaymentSimulationService::class)->simulate(Invoice::factory()->create(['status' => 'paid']));
    }
}