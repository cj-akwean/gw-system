<?php

namespace Tests\Feature;

use App\Livewire\AdminDatabaseNotifications;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class AdminDatabaseNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email' => 'admin@example.com', 'is_admin' => true]);
    }

    private function createNotification(User $user, array $data = []): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::orderedUuid(),
            'type' => \Filament\Notifications\DatabaseNotification::class,
            'data' => array_merge([
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice X never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [],
            ], $data),
        ]);
    }

    public function test_dismissing_a_notification_marks_it_read_instead_of_deleting_it(): void
    {
        $admin = $this->admin();
        $notification = $this->createNotification($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->dispatch('notificationClosed', ['id' => $notification->getKey()]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_dismissing_a_non_uuid_id_is_a_safe_noop(): void
    {
        $admin = $this->admin();
        $notification = $this->createNotification($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->dispatch('notificationClosed', ['id' => 'not-a-uuid']);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_dismissing_an_already_read_notification_is_idempotent(): void
    {
        $admin = $this->admin();
        $notification = $this->createNotification($admin, []);
        $notification->update(['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->dispatch('notificationClosed', ['id' => $notification->getKey()]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_listener_parameter_is_named_id_for_the_browser_contract(): void
    {
        // The browser forwards the window CustomEvent detail {id: ...} as a
        // NAMED argument; Livewire container-autowires on a name mismatch,
        // which crashed with BindingResolutionException when the param was
        // named $payload (regression 2026-08-07). The name must stay $id.
        $param = (new ReflectionMethod(AdminDatabaseNotifications::class, 'removeNotification'))
            ->getParameters()[0];

        $this->assertSame('id', $param->getName());
    }

    public function test_clear_notifications_action_is_hidden(): void
    {
        $admin = $this->admin();
        $this->createNotification($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->assertActionHidden('clearNotifications');
    }

    public function test_mark_all_as_read_still_works_after_the_override(): void
    {
        $admin = $this->admin();

        $unread = $this->createNotification($admin);
        $read = $this->createNotification($admin, ['title' => 'Second failure']);
        $read->update(['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->call('markAllNotificationsAsRead');

        $this->assertNotNull($unread->fresh()->read_at);
        $this->assertNotNull($read->fresh()->read_at);
    }

    public function test_bell_trigger_renders_with_the_unread_badge(): void
    {
        $admin = $this->admin();
        $this->createNotification($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->assertSee('fi-topbar-database-notifications-btn');
    }

    public function test_bell_trigger_does_not_show_a_badge_when_everything_is_read(): void
    {
        $admin = $this->admin();
        $this->createNotification($admin);
        $admin->notifications()->update(['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->assertSee('fi-topbar-database-notifications-btn');
    }

    public function test_bell_is_present_on_admin_pages(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('fi-topbar-database-notifications-btn');
    }

    public function test_bell_polls_every_ten_seconds(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin')->assertOk();

        $this->assertSame('10s', \Filament\Facades\Filament::getDatabaseNotificationsPollingInterval());
    }

    public function test_bell_render_dispatches_sidebar_refresh(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin, 'admin')
            ->test(AdminDatabaseNotifications::class)
            ->assertDispatched('refresh-sidebar');
    }
}
