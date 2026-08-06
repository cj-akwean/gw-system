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
use RuntimeException;
use Tests\TestCase;

class ResendReceiptControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
    }

    private function linkUser(Invoice $invoice, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        ConnectionLink::factory()->create([
            'user_id' => $user->id,
            'service_connection_id' => $invoice->service_connection_id,
            'status' => 'active',
            'linked_at' => now(),
            'unlinked_at' => null,
        ]);

        return $user;
    }

    /**
     * @return array{Invoice, Payment}
     */
    private function payableScenario(string $email = 'renter@example.com'): array
    {
        $invoice = Invoice::factory()->create(['status' => 'paid']);
        $this->linkUser($invoice, $email);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total_amount,
            'method' => 'paymongo',
        ]);

        return [$invoice, $payment];
    }

    public function test_guest_is_redirected_away(): void
    {
        $payment = Payment::factory()->create();

        $this->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect();
    }

    public function test_non_admin_user_is_redirected_away(): void
    {
        $regular = User::factory()->create(['email' => 'boarder@example.com']);
        $payment = Payment::factory()->create();

        $this->actingAs($regular, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect();
    }

    public function test_admin_resends_the_receipt_and_is_redirected_to_the_dashboard(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class, fn (PaymentConfirmation $mail) => $mail->hasTo('renter@example.com'));
    }

    public function test_no_linked_recipients_skips_without_sending(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();
        ConnectionLink::query()->delete();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertNothingSent();
    }

    public function test_failed_job_notification_carries_a_resend_action_with_the_route(): void
    {
        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $notification = $admin->notifications()->first();
        $this->assertNotNull($notification);

        $data = $notification->data;

        $this->assertStringContainsString('never reached the customer', $data['body']);
        $this->assertStringNotContainsString('php artisan', $data['body']);
        $this->assertSame('resendReceipt', $data['actions'][0]['name']);
        $this->assertSame(route('admin.payments.resend-receipt', $payment), $data['actions'][0]['url']);
    }
}
