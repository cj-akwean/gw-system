<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentConfirmationEmail;
use App\Mail\PaymentConfirmation;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendPaymentConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function linkUser(Invoice $invoice, string $email, string $status = 'active'): User
    {
        $user = User::factory()->create(['email' => $email]);

        $state = $status === 'active'
            ? ['status' => 'active', 'unlinked_at' => null]
            : ['status' => 'revoked', 'unlinked_at' => now()->subDays(3)];

        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $invoice->service_connection_id,
            'linked_at' => now(),
        ] + $state);

        return $user;
    }

    private function paymentFor(Invoice $invoice): Payment
    {
        return Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'method' => 'paymongo',
        ]);
    }

    private function runJob(Invoice $invoice, Payment $payment): void
    {
        (new SendPaymentConfirmationEmail($invoice, $payment))->handle();
    }

    public function test_sends_to_every_active_linked_user(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, 'renter@example.com');
        $this->linkUser($invoice, 'boarder@example.com');
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('renter@example.com'));
        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('boarder@example.com'));
    }

    public function test_emails_differing_only_by_case_are_deduped(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, 'shared@example.com');
        $this->linkUser($invoice, 'SHARED@example.com');
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertSent(PaymentConfirmation::class, 1);
        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('shared@example.com'));
    }

    public function test_revoked_links_do_not_receive_the_email(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, 'current@example.com', 'active');
        $this->linkUser($invoice, 'movedout@example.com', 'revoked');
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('current@example.com'));
        Mail::assertNotSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('movedout@example.com'));
    }

    public function test_invalid_emails_are_filtered_out(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, 'valid@example.com');
        $this->linkUser($invoice, 'not an email');
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertSent(PaymentConfirmation::class, 1);
        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('valid@example.com'));
    }

    public function test_no_active_linked_users_sends_nothing(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertNothingSent();
    }

    public function test_the_invoice_pdf_is_attached(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'GW-2026-12345',
        ]);
        $payment = $this->paymentFor($invoice);

        $mail = new PaymentConfirmation($invoice, $payment);
        $attachments = $mail->attachments();

        $this->assertCount(1, $attachments);
        $this->assertSame('invoice-GW-2026-12345.pdf', $attachments[0]->as);
        $this->assertSame('application/pdf', $attachments[0]->mime);

        $pdf = $attachments[0]->attachWith(fn () => null, fn ($data) => $data());
        $this->assertStringContainsString('%PDF', $pdf);
    }

    public function test_the_markdown_body_renders_with_invoice_and_amount(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'GW-2026-67890',
            'total_amount' => 40.00,
        ]);
        $payment = $this->paymentFor($invoice);

        $html = (new PaymentConfirmation($invoice, $payment))->render();

        $this->assertStringContainsString('GW-2026-67890', $html);
        $this->assertStringContainsString('40.00', $html);
    }
}
