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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    public function test_failed_job_notification_carries_a_resend_action_with_the_route_path(): void
    {
        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $notification = $admin->notifications()->first();
        $this->assertNotNull($notification);

        $data = $notification->data;

        $path = parse_url(route('admin.payments.resend-receipt', $payment), PHP_URL_PATH);

        $this->assertStringContainsString('never reached the customer', $data['body']);
        $this->assertStringNotContainsString('php artisan', $data['body']);
        $this->assertSame('resendReceipt', $data['actions'][0]['name']);
        $this->assertSame($path, $data['actions'][0]['url']);
    }

    public function test_failed_job_notification_is_tagged_with_payment_and_invoice_ids(): void
    {
        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();

        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $data = $admin->notifications()->first()->data;

        $this->assertSame($payment->id, $data['payment_id']);
        $this->assertSame($invoice->id, $data['invoice_id']);
    }

    public function test_resend_marks_the_notification_resolved(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();
        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class);

        $notification = $admin->notifications()->first();
        $data = $notification->fresh()->data;

        $this->assertArrayHasKey('resolved_at', $data);
        $this->assertSame(1, $data['resend_count']);
        $this->assertSame('Payment confirmation email resent', $data['title']);
        $this->assertStringContainsString('Receipt resent to renter@example.com', $data['body']);
        $this->assertSame('success', $data['color']);
        $this->assertSame([], $data['actions']);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_second_resend_is_blocked_without_a_duplicate_email(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();
        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_resend_is_blocked_when_any_admin_has_already_resent(): void
    {
        Mail::fake();

        $otherAdmin = User::factory()->create(['email' => 'other-admin@example.com', 'is_admin' => true]);
        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();
        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->actingAs($otherAdmin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment));

        Mail::assertSent(PaymentConfirmation::class, 1);

        $this->assertArrayHasKey('resolved_at', $admin->notifications()->first()->fresh()->data);
    }

    public function test_failed_resend_leaves_the_notification_untouched(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.port', 1);
        config()->set('mail.mailers.smtp.timeout', 2);

        $admin = $this->admin();
        [$invoice, $payment] = $this->payableScenario();
        (new SendPaymentConfirmationEmail($invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $data = $admin->notifications()->first()->fresh()->data;

        $this->assertArrayNotHasKey('resolved_at', $data);
        $this->assertSame('resendReceipt', $data['actions'][0]['name']);
        $this->assertStringContainsString('never reached the customer', $data['body']);
    }

    public function test_no_recipients_does_not_mark_the_notification_resolved(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();
        ConnectionLink::query()->delete();
        (new SendPaymentConfirmationEmail($payment->invoice, $payment))->failed(new RuntimeException('SMTP unreachable'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertNothingSent();

        $data = $admin->notifications()->first()->fresh()->data;
        $this->assertArrayNotHasKey('resolved_at', $data);
    }

    public function test_legacy_untagged_notification_is_found_and_resolved(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $notification = $admin->notifications()->create([
            'id' => '00000000-0000-0000-0000-000000000001',
            'type' => DatabaseNotification::class,
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice X (payment #'.$payment->id.') never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => route('admin.payments.resend-receipt', $payment),
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class);

        $data = $notification->fresh()->data;
        $this->assertArrayHasKey('resolved_at', $data);
        $this->assertSame([], $data['actions']);
    }

    public function test_resend_route_is_rate_limited(): void
    {
        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $this->actingAs($admin, 'admin');

        for ($i = 0; $i < 10; $i++) {
            $this->get(route('admin.payments.resend-receipt', $payment))->assertRedirect();
        }

        $this->get(route('admin.payments.resend-receipt', $payment))->assertStatus(429);
    }

    public function test_legacy_notification_with_foreign_host_url_is_found_and_resolved(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $notification = $admin->notifications()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => DatabaseNotification::class,
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice X (payment #'.$payment->id.') never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => 'http://legacy-other-host:8080/admin/payments/'.$payment->id.'/resend-receipt',
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class, 1);

        $data = $notification->fresh()->data;
        $this->assertArrayHasKey('resolved_at', $data);
        $this->assertSame([], $data['actions']);
    }

    public function test_mixed_resolved_and_unresolved_copies_resolves_only_the_pending_one(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $resolvedCopy = $admin->notifications()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => DatabaseNotification::class,
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent at 2026-08-06 10:00:00.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'payment_id' => (string) $payment->id,
                'resolved_at' => '2026-08-06T02:00:00.000000Z',
                'resend_count' => 1,
            ],
        ]);

        $pendingCopy = $admin->notifications()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => DatabaseNotification::class,
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice X (payment #'.$payment->id.') never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => route('admin.payments.resend-receipt', $payment),
                    ],
                ],
                'payment_id' => (string) $payment->id,
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class, 1);

        $this->assertSame($resolvedCopy->fresh()->data['resolved_at'], '2026-08-06T02:00:00.000000Z');
        $this->assertSame($resolvedCopy->fresh()->data['resend_count'], 1);
        $this->assertSame($resolvedCopy->fresh()->data['title'], 'Payment confirmation email resent');
        $this->assertSame($resolvedCopy->fresh()->data['actions'], []);

        $pendingData = $pendingCopy->fresh()->data;
        $this->assertArrayHasKey('resolved_at', $pendingData);
        $this->assertSame(1, $pendingData['resend_count']);
        $this->assertSame([], $pendingData['actions']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment));

        Mail::assertSent(PaymentConfirmation::class, 1);
    }

    public function test_non_filament_notification_with_matching_payment_id_is_never_touched(): void
    {
        Mail::fake();

        $admin = $this->admin();
        [, $payment] = $this->payableScenario();

        $foreign = $admin->notifications()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => DatabaseNotification::class,
            'data' => [
                'format' => 'customer',
                'duration' => 'persistent',
                'title' => 'Payment received',
                'body' => 'Your payment was recorded.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => 'http://nope.example/payments/'.$payment->id.'/resend-receipt',
                    ],
                ],
                'payment_id' => (string) $payment->id,
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.payments.resend-receipt', $payment))
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        Mail::assertSent(PaymentConfirmation::class, 1);

        $data = $foreign->fresh()->data;
        $this->assertArrayNotHasKey('resolved_at', $data);
        $this->assertArrayHasKey('payment_id', $data);
    }
}
