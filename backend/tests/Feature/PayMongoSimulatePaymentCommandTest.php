<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentConfirmationEmail;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayMongoSimulatePaymentCommandTest extends TestCase
{
    use RefreshDatabase;

    private function unpaidInvoice(array $overrides = []): Invoice
    {
        return Invoice::factory()->create(array_merge(['status' => 'unpaid'], $overrides));
    }

    public function test_command_marks_an_unpaid_invoice_paid_and_records_the_payment(): void
    {
        $invoice = $this->unpaidInvoice(['total_amount' => 456.56]);

        $exitCode = Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('paid', $invoice->fresh()->status);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('paymongo', $payment->method);
        $this->assertSame('card', $payment->paymongo_source);
        $this->assertSame(456.56, (float) $payment->amount);
        $this->assertStringStartsWith('pay_sim_', $payment->paymongo_reference);

        $this->assertDatabaseHas('processed_webhook_events', ['event_type' => 'payment.paid']);
        $this->assertStringContainsString('marked paid', Artisan::output());
    }

    public function test_command_accepts_an_invoice_number(): void
    {
        $invoice = $this->unpaidInvoice();

        $exitCode = Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->invoice_number]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_command_defaults_to_the_first_unpaid_invoice(): void
    {
        Invoice::factory()->create(['status' => 'paid']);
        $target = $this->unpaidInvoice();

        $exitCode = Artisan::call('paymongo:simulate-payment');

        $this->assertSame(0, $exitCode);
        $this->assertSame('paid', $target->fresh()->status);
    }

    public function test_command_fabricates_an_intent_id_when_the_invoice_has_none(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->assertNull($invoice->paymongo_payment_intent_id);

        Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id]);

        $this->assertStringStartsWith('pi_sim_', $invoice->fresh()->paymongo_payment_intent_id);
        $this->assertStringContainsString('fabricated a simulation intent', Artisan::output());
    }

    public function test_command_reuses_an_existing_stored_intent(): void
    {
        $invoice = $this->unpaidInvoice(['paymongo_payment_intent_id' => 'pi_real_123']);

        Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id]);

        $this->assertSame('pi_real_123', $invoice->fresh()->paymongo_payment_intent_id);
    }

    public function test_command_queues_the_confirmation_email(): void
    {
        Queue::fake();

        $invoice = $this->unpaidInvoice();

        Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id]);

        Queue::assertPushed(SendPaymentConfirmationEmail::class);
    }

    public function test_command_records_custom_source_and_payer_options(): void
    {
        $invoice = $this->unpaidInvoice();

        $exitCode = Artisan::call('paymongo:simulate-payment', [
            'invoice' => $invoice->id,
            '--source' => 'gcash',
            '--payer-name' => 'Zooey Doge',
            '--payer-email' => 'zooey@example.com',
            '--payer-phone' => '09171234567',
        ]);

        $this->assertSame(0, $exitCode);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('gcash', $payment->paymongo_source);
        $this->assertSame('Zooey Doge', $payment->payer_name);
        $this->assertSame('zooey@example.com', $payment->payer_email);
        $this->assertSame('09171234567', $payment->payer_phone);
    }

    public function test_command_records_a_qrph_source(): void
    {
        $invoice = $this->unpaidInvoice();

        $exitCode = Artisan::call('paymongo:simulate-payment', [
            'invoice' => $invoice->id,
            '--source' => 'qrph',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('paid', $invoice->fresh()->status);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('paymongo', $payment->method);
        $this->assertSame('qrph', $payment->paymongo_source);
        $this->assertStringContainsString('qrph', Artisan::output());
    }

    public function test_command_defaults_payer_to_the_first_linked_user(): void
    {
        $invoice = $this->unpaidInvoice();
        $user = User::factory()->create(['name' => 'Renter One', 'email' => 'renter@example.com', 'phone' => '09170000000']);
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $invoice->service_connection_id,
            'status' => 'active',
        ]);

        Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id]);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('Renter One', $payment->payer_name);
        $this->assertSame('renter@example.com', $payment->payer_email);
        $this->assertSame('09170000000', $payment->payer_phone);
    }

    public function test_command_rejects_an_already_paid_invoice(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->unpaidInvoice(['status' => 'paid', 'total_amount' => 100.00]);

        $exitCode = Artisan::call('paymongo:simulate-payment', ['invoice' => $invoice->id + 1]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('paid', Invoice::find($invoice->id + 1)->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('processed_webhook_events', 0);
    }

    public function test_command_fails_for_an_unknown_invoice(): void
    {
        $exitCode = Artisan::call('paymongo:simulate-payment', ['invoice' => 999_999]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_fails_when_no_unpaid_invoice_exists(): void
    {
        Invoice::factory()->create(['status' => 'paid']);

        $exitCode = Artisan::call('paymongo:simulate-payment');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invoice not found', Artisan::output());
    }

    public function test_command_rejects_an_overlong_source_channel(): void
    {
        $invoice = $this->unpaidInvoice();

        $exitCode = Artisan::call('paymongo:simulate-payment', [
            'invoice' => $invoice->id,
            '--source' => str_repeat('x', 31),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }
}
