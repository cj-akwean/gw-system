<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentConfirmationEmail;
use App\Mail\PaymentConfirmation;
use App\Models\ConnectionLink;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
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

        Mail::assertSent(PaymentConfirmation::class, 1);
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

    public function test_the_markdown_body_shows_the_paymongo_reference_when_reference_is_null(): void
    {
        $invoice = Invoice::factory()->create([
            'status' => 'paid',
            'invoice_number' => 'GW-2026-67891',
        ]);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'method' => 'paymongo',
            'reference' => null,
            'paymongo_reference' => 'pay_reference_email_1',
        ]);

        $html = (new PaymentConfirmation($invoice, $payment))->render();

        $this->assertStringContainsString('pay_reference_email_1', $html);
    }

    public function test_active_link_with_unlinked_at_set_is_excluded(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, 'current@example.com');

        $odd = User::factory()->create(['email' => 'odd@example.com']);
        ConnectionLink::factory()->create([
            'user_id' => $odd->id,
            'service_connection_id' => $invoice->service_connection_id,
            'status' => 'active',
            'linked_at' => now(),
            'unlinked_at' => now()->subDay(),
        ]);
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('current@example.com'));
        Mail::assertNotSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('odd@example.com'));
    }

    public function test_invoice_without_a_service_connection_does_not_crash(): void
    {
        Mail::fake();

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $invoice->service_connection_id = null;
        $payment = $this->paymentFor($invoice);

        $this->runJob($invoice, $payment);

        Mail::assertNothingSent();
    }

    public function test_failed_job_logs_to_the_paymongo_channel(): void
    {
        config()->set('logging.channels.paymongo', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);

        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $payment = $this->paymentFor($invoice);

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        /** @var \Illuminate\Log\Logger $logger */
        $logger = Log::channel('paymongo');
        /** @var Logger $monolog */
        $monolog = $logger->getLogger();
        /** @var TestHandler $handler */
        $handler = $monolog->getHandlers()[0];
        $records = $handler->getRecords();
        $last = end($records);

        $this->assertSame(Logger::ERROR, $last['level']);
        $this->assertStringContainsString('failed permanently', $last['message']);
        $this->assertSame($payment->id, $last['context']['payment_id']);
    }

    public function test_failed_job_sends_a_database_notification_to_every_admin(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
        $regular = User::factory()->create(['email' => 'boarder@example.com']);
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $payment = $this->paymentFor($invoice);

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'notifiable_type' => User::class,
            'type' => DatabaseNotification::class,
        ]);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $regular->id]);
    }

    public function test_failed_job_creates_no_notification_when_there_are_no_admins(): void
    {
        User::factory()->create(['email' => 'boarder@example.com']);
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $payment = $this->paymentFor($invoice);

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->assertDatabaseCount('notifications', 0);
    }
}
