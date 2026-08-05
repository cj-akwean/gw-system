<?php

namespace Tests\Feature;

use App\Mail\PaymentConfirmation;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayMongoSendReceiptCommandTest extends TestCase
{
    use RefreshDatabase;

    private function paidInvoiceWithLinkedUser(string $email = 'renter@example.com'): Invoice
    {
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $user = User::factory()->create(['email' => $email]);
        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $invoice->service_connection_id,
            'status' => 'active',
        ]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'method' => 'paymongo',
        ]);

        return $invoice;
    }

    public function test_command_resends_the_receipt_to_linked_users(): void
    {
        Mail::fake();

        $invoice = $this->paidInvoiceWithLinkedUser();
        $this->paidInvoiceWithLinkedUser('boarder@example.com');

        $exitCode = Artisan::call('paymongo:send-receipt', ['invoice' => $invoice->id]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Receipt sent', Artisan::output());
        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('renter@example.com'));
        Mail::assertNotSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('boarder@example.com'));
    }

    public function test_command_fails_for_an_unknown_invoice(): void
    {
        $exitCode = Artisan::call('paymongo:send-receipt', ['invoice' => 999_999]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_fails_when_the_invoice_has_no_payment(): void
    {
        $invoice = Invoice::factory()->create(['status' => 'unpaid']);

        $exitCode = Artisan::call('paymongo:send-receipt', ['invoice' => $invoice->id]);

        $this->assertSame(1, $exitCode);
    }

    public function test_command_skips_when_there_are_no_recipients(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'method' => 'paymongo',
        ]);

        $exitCode = Artisan::call('paymongo:send-receipt', ['invoice' => $invoice->id]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('skipped', Artisan::output());
        Mail::assertNothingSent();
    }
}
