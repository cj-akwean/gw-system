<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.env' => 'production']);
    }

    public function test_admin_user_can_access_panel_in_production(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user, 'admin')
            ->get('/admin')
            ->assertOk();
    }

    public function test_non_admin_user_is_rejected_in_production(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user, 'admin')
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
