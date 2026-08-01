<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_password_shows_generic_error_message(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'is_admin' => true,
        ]);

        $component = Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])
            ->call('authenticate');

        $component->assertHasFormErrors(['email']);
        $this->assertStringContainsString('Incorrect email or password.', $component->html());
    }

    public function test_unknown_email_shows_generic_error_message(): void
    {
        $component = Livewire::test(Login::class)
            ->fillForm([
                'email' => 'nobody@example.com',
                'password' => 'whatever',
            ])
            ->call('authenticate');

        $component->assertHasFormErrors(['email']);
        $this->assertStringContainsString('Incorrect email or password.', $component->html());
    }

    public function test_valid_credentials_but_non_admin_shows_panel_access_error(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'is_admin' => false,
            'password' => 'password',
        ]);

        $component = Livewire::test(Login::class)
            ->fillForm([
                'email' => 'customer@example.com',
                'password' => 'password',
            ])
            ->call('authenticate');

        $component->assertHasFormErrors(['email']);
        $this->assertStringContainsString('This account does not have access to the admin panel.', $component->html());
    }

    public function test_admin_with_valid_credentials_signs_in(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'is_admin' => true,
            'password' => 'password',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@example.com',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();
    }
}
