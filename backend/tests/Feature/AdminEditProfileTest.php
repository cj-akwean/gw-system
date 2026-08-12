<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class AdminEditProfileTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'email' => 'admin@example.com',
            'is_admin' => true,
            'password' => 'old-password-1',
            'avatar_id' => null,
        ]);
    }

    public function test_profile_page_renders_for_admin(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/profile')
            ->assertOk()
            ->assertSee('Avatar');
    }

    public function test_admin_can_save_name_email_and_avatar(): void
    {
        Mail::fake();

        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'avatar_id' => 2,
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Office Admin', $admin->fresh()->name);
        $this->assertSame(2, $admin->fresh()->avatar_id);
        Mail::assertNothingQueued();
    }

    public function test_avatar_outside_the_shared_set_is_rejected(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'avatar_id' => 9,
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
            ])
            ->call('save')
            ->assertHasFormErrors(['avatar_id']);

        $this->assertNull($admin->fresh()->avatar_id);
    }

    public function test_email_must_be_unique_across_all_users(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'Office Admin',
                'email' => 'taken@example.com',
            ])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertSame('admin@example.com', $admin->fresh()->email);
    }

    public function test_password_change_updates_hash_and_queues_email(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $code = $this->requestChangeCode($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
                'currentPassword' => 'old-password-1',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'otp' => $code,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-1', $admin->fresh()->password));
        Mail::assertQueued(\App\Mail\PasswordChanged::class);
    }

    public function test_password_change_without_otp_is_halted(): void
    {
        Mail::fake();

        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
                'currentPassword' => 'old-password-1',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
            ])
            ->call('save')
            ->assertNotified();

        $this->assertTrue(Hash::check('old-password-1', $admin->fresh()->password));
        Mail::assertNothingQueued();
    }

    public function test_password_change_with_a_wrong_otp_is_halted(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $this->requestChangeCode($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
                'currentPassword' => 'old-password-1',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'otp' => '000000',
            ])
            ->call('save')
            ->assertNotified();

        $this->assertTrue(Hash::check('old-password-1', $admin->fresh()->password));
        // The OTP mailable from requestChangeCode is expected; the change
        // itself must never fire.
        Mail::assertNotQueued(\App\Mail\PasswordChanged::class);
    }

    public function test_send_code_action_emails_the_otp(): void
    {
        Mail::fake();

        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-1',
            ])
            ->call('sendPasswordChangeCode')
            ->assertNotified();

        Mail::assertQueued(\App\Mail\PasswordChangeOtp::class);
    }

    private function requestChangeCode(User $admin): string
    {
        Mail::fake();

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm(['password' => 'new-password-1'])
            ->call('sendPasswordChangeCode');

        return Mail::queued(\App\Mail\PasswordChangeOtp::class)->first()->code;
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $code = $this->requestChangeCode($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(EditProfile::class)
            ->fillForm([
                'name' => 'Office Admin',
                'email' => 'admin@example.com',
                'currentPassword' => 'wrong-password',
                'password' => 'new-password-1',
                'passwordConfirmation' => 'new-password-1',
                'otp' => $code,
            ])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check('old-password-1', $admin->fresh()->password));
    }

    public function test_avatar_url_uses_static_svg_when_set(): void
    {
        $admin = $this->admin();
        $admin->update(['avatar_id' => 3]);

        $this->assertStringContainsString('avatars/avatar-3.svg', $admin->getFilamentAvatarUrl());
    }

    public function test_avatar_url_falls_back_when_not_set(): void
    {
        $admin = $this->admin();

        $this->assertNull($admin->getFilamentAvatarUrl());
    }
}
