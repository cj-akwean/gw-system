<?php

namespace Tests\Feature;

use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Auth\ResetPassword;
use App\Mail\PasswordResetOtp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'is_admin' => true,
            'password' => 'old-password-1',
        ]);
    }

    public function test_request_page_is_reachable(): void
    {
        $this->get('/admin/password-reset/request')->assertOk();
    }

    public function test_request_emails_a_code_and_shows_success(): void
    {
        Mail::fake();

        $this->admin();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@example.com'])
            ->call('request')
            ->assertNotified();

        Mail::assertQueued(PasswordResetOtp::class);
    }

    public function test_request_does_not_email_a_non_admin(): void
    {
        Mail::fake();

        User::factory()->create(['email' => 'boarder@example.com', 'is_admin' => false]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'boarder@example.com'])
            ->call('request');

        Mail::assertNothingQueued();
    }

    public function test_reset_page_is_reachable(): void
    {
        $this->get('/admin/password-reset/reset-code')->assertOk();
    }

    public function test_reset_with_otp_changes_the_admin_password(): void
    {
        Mail::fake();

        $admin = $this->admin();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@example.com'])
            ->call('request');

        $code = Mail::queued(PasswordResetOtp::class)->first()->code;

        Livewire::test(ResetPassword::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'otp' => $code,
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
            ])
            ->call('resetPassword')
            ->assertNotified();

        $this->assertTrue(Hash::check('new-password-1', $admin->fresh()->password));
    }

    public function test_reset_with_a_wrong_otp_is_rejected(): void
    {
        Mail::fake();

        $admin = $this->admin();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'admin@example.com'])
            ->call('request');

        Livewire::test(ResetPassword::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'otp' => '000000',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('old-password-1', $admin->fresh()->password));
    }

    public function test_reset_does_not_change_a_non_admin_password(): void
    {
        Mail::fake();

        $boarder = User::factory()->create([
            'email' => 'boarder@example.com',
            'is_admin' => false,
            'password' => 'old-password-1',
        ]);

        Livewire::test(ResetPassword::class)
            ->fillForm([
                'email' => 'boarder@example.com',
                'otp' => '123456',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
            ])
            ->call('resetPassword');

        $this->assertTrue(Hash::check('old-password-1', $boarder->fresh()->password));
    }
}
