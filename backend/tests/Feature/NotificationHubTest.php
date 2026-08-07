<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationHub;
use App\Models\User;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification as DatabaseNotificationModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationHubTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email = 'admin@example.com'): User
    {
        return User::factory()->create(['email' => $email, 'is_admin' => true]);
    }

    private function notify(User $user, array $overrides = []): DatabaseNotificationModel
    {
        $data = $overrides['data'] ?? [
            'format' => 'filament',
            'duration' => 'persistent',
            'title' => 'Payment confirmation email failed',
            'body' => 'Invoice GW-2026-00001 (payment #1) never reached the customer.',
            'color' => 'danger',
            'status' => 'danger',
            'actions' => [
                [
                    'name' => 'resendReceipt',
                    'label' => 'Resend receipt',
                    'url' => route('admin.payments.resend-receipt', 1),
                ],
            ],
        ];

        $attributes = array_merge([
            'id' => (string) Str::orderedUuid(),
            'type' => FilamentDatabaseNotification::class,
            'data' => $data,
        ], $overrides);

        return $user->notifications()->create($attributes);
    }

    public function test_guest_is_redirected_away_from_the_hub(): void
    {
        $this->get('/admin/notifications')->assertRedirect();
    }

    public function test_non_admin_user_cannot_access_the_hub(): void
    {
        $regular = User::factory()->create(['email' => 'boarder@example.com', 'is_admin' => false]);

        $this->actingAs($regular, 'admin')
            ->get('/admin/notifications')
            ->assertForbidden();
    }

    public function test_admin_can_open_the_hub(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get('/admin/notifications')
            ->assertOk();
    }

    public function test_hub_lists_read_unread_and_resolved_notifications(): void
    {
        $admin = $this->admin();

        $unread = $this->notify($admin);
        $read = $this->notify($admin, ['read_at' => now()]);
        $resolved = $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent to renter@example.com.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'resolved_at' => now()->toISOString(),
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertCanSeeTableRecords([$unread, $read, $resolved])
            ->assertSee('Unread')
            ->assertSee('Read')
            ->assertSee('Resolved');
    }

    public function test_admin_never_sees_other_admins_notifications(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin('other@example.com');

        $own = $this->notify($admin);
        $foreign = $this->notify($otherAdmin);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_unread_filter_shows_only_unread_rows(): void
    {
        $admin = $this->admin();

        $this->notify($admin);
        $this->notify($admin, ['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->filterTable('state', 'unread')
            ->assertCountTableRecords(1);
    }

    public function test_read_filter_shows_only_read_rows(): void
    {
        $admin = $this->admin();

        $this->notify($admin);
        $this->notify($admin, ['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->filterTable('state', 'read')
            ->assertCountTableRecords(1);
    }

    public function test_resolved_filter_uses_the_resolution_flag_not_read_at(): void
    {
        $admin = $this->admin();

        $resolvedUnread = $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'resolved_at' => now()->toISOString(),
            ],
        ]);
        $pending = $this->notify($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->filterTable('state', 'resolved')
            ->assertCanSeeTableRecords([$resolvedUnread])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_action_needed_filter_shows_only_unresolved_action_rows(): void
    {
        $admin = $this->admin();

        $pending = $this->notify($admin);
        $resolved = $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'resolved_at' => now()->toISOString(),
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->filterTable('state', 'action_needed')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$resolved]);
    }

    public function test_mark_as_read_row_action_sets_read_at(): void
    {
        $admin = $this->admin();
        $notification = $this->notify($admin);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->callTableAction('markAsRead', $notification->getKey());

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_as_unread_row_action_clears_read_at(): void
    {
        $admin = $this->admin();
        $notification = $this->notify($admin, ['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->callTableAction('markAsUnread', $notification->getKey());

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_mark_all_as_read_only_touches_own_unread_rows(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin('other@example.com');

        $ownUnread = $this->notify($admin);
        $ownRead = $this->notify($admin, ['read_at' => now()]);
        $otherUnread = $this->notify($otherAdmin);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->call('markAllNotificationsAsRead');

        $this->assertNotNull($ownUnread->fresh()->read_at);
        $this->assertNotNull($ownRead->fresh()->read_at);
        $this->assertNull($otherUnread->fresh()->read_at);
    }

    public function test_action_column_shows_the_stored_action_url_until_resolved(): void
    {
        $admin = $this->admin();

        $pending = $this->notify($admin);
        $resolved = $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'resolved_at' => now()->toISOString(),
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertCanSeeTableRecords([$pending, $resolved])
            ->assertSee('Resend receipt');
    }

    public function test_resolved_notification_that_is_still_unread_shows_resolved_state(): void
    {
        $admin = $this->admin();

        $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email resent',
                'body' => 'Receipt resent.',
                'color' => 'success',
                'status' => 'success',
                'actions' => [],
                'resolved_at' => now()->toISOString(),
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertSee('Resolved');
    }

    public function test_notification_without_an_actions_key_renders_without_error(): void
    {
        $admin = $this->admin();

        $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Identifier change email failed',
                'body' => 'Re-save the connection to retry.',
                'color' => 'danger',
                'status' => 'danger',
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertSee('Identifier change email failed');
    }

    public function test_action_link_is_rebuilt_from_payment_id_when_the_stored_url_is_foreign(): void
    {
        $admin = $this->admin();

        $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice GW-2026-00008 (payment #8) never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => 'http://legacy-other-host:8080/admin/payments/8/resend-receipt',
                    ],
                ],
                'payment_id' => 8,
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertSee(route('admin.payments.resend-receipt', 8))
            ->assertDontSee('http://legacy-other-host:8080');
    }

    public function test_action_link_falls_back_to_the_path_when_payment_id_is_missing(): void
    {
        $admin = $this->admin();

        $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice GW-2026-00008 (payment #8) never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => 'http://some-old-host.example/admin/payments/8/resend-receipt',
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertSee(route('admin.payments.resend-receipt', 8))
            ->assertDontSee('http://some-old-host.example');
    }

    public function test_action_label_without_a_resolvable_link_renders_plain_text(): void
    {
        $admin = $this->admin();

        $this->notify($admin, [
            'data' => [
                'format' => 'filament',
                'duration' => 'persistent',
                'title' => 'Payment confirmation email failed',
                'body' => 'Invoice GW-2026-00008 (payment #8) never reached the customer.',
                'color' => 'danger',
                'status' => 'danger',
                'actions' => [
                    [
                        'name' => 'resendReceipt',
                        'label' => 'Resend receipt',
                        'url' => 'http://totally-unrelated.example/some/other/path',
                    ],
                ],
            ],
        ]);

        Livewire::actingAs($admin, 'admin')
            ->test(NotificationHub::class)
            ->assertSee('Resend receipt')
            ->assertDontSee(route('admin.payments.resend-receipt', 8))
            ->assertDontSee('http://totally-unrelated.example');
    }

    public function test_nav_badge_counts_only_own_unread_filament_notifications(): void
    {
        $admin = $this->admin();
        $otherAdmin = $this->admin('other@example.com');

        $this->notify($admin);
        $this->notify($admin, ['read_at' => now()]);
        $this->notify($otherAdmin);

        Livewire::actingAs($admin, 'admin')->test(NotificationHub::class);

        $this->assertSame('1', NotificationHub::getNavigationBadge());
        $this->assertSame('danger', NotificationHub::getNavigationBadgeColor());
    }

    public function test_nav_badge_is_null_when_nothing_is_unread(): void
    {
        $admin = $this->admin();
        $this->notify($admin, ['read_at' => now()]);

        Livewire::actingAs($admin, 'admin')->test(NotificationHub::class);

        $this->assertNull(NotificationHub::getNavigationBadge());
        $this->assertNull(NotificationHub::getNavigationBadgeColor());
    }
}
