<?php

namespace App\Livewire;

use Filament\Actions\Action;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Admin-panel variant of Filament's database-notifications bell.
 *
 * The stock bell treats "dismiss" as destruction: the per-row X button
 * (`notificationClosed`) and the header "Clear" action DELETE rows, so any
 * notification that was read or dismissed is gone forever and no history
 * exists anywhere. The full history now lives in
 * `App\Filament\Pages\NotificationHub` — for it to be truthful, dismiss here
 * marks the row as read instead of deleting it, and the destructive "Clear"
 * action is removed. Only `read_at` changes; the `data` payload (resolved_at,
 * resend_count, action URLs) is untouched, so the ResendReceiptController
 * resolution flow behaves exactly as before.
 *
 * Must extend `Filament\Livewire\DatabaseNotifications` (the panel-aware
 * class), not the base `Filament\Notifications\Livewire\DatabaseNotifications`:
 * the panel-aware `getTrigger()` returns the topbar/sidebar bell-icon view, while
 * the base returns null — a null trigger renders a modal with no button, which
 * makes the bell completely invisible in the header (regression 2026-08-07).
 */
class AdminDatabaseNotifications extends DatabaseNotifications
{
    /**
     * Marks a dismissed notification as read (never deleted — the Hub is the
     * history). The parameter MUST stay named `$id` (Filament's contract):
     * the browser forwards the window CustomEvent detail `{id: ...}` as a
     * NAMED argument, and Livewire falls back to container autowiring on a
     * name mismatch — a required `array|string $payload` param then explodes
     * with BindingResolutionException (hit 2026-08-07 on the billing page).
     * The array branch keeps Livewire's test dispatcher (positional array)
     * working.
     */
    #[On('notificationClosed')]
    public function removeNotification(string|array $id): void
    {
        if (is_array($id)) {
            $id = $id['id'] ?? null;
        }

        if (! is_string($id) || ! Str::isUuid($id)) {
            return;
        }

        $this->getNotificationsQuery()
            ->where('id', $id)
            ->update(['read_at' => now()]);
    }

    public function clearNotificationsAction(): Action
    {
        return parent::clearNotificationsAction()->hidden();
    }

    /**
     * Every render of the bell (mount, poll tick, incoming events) also
     * refreshes the sidebar, so the "Notification Hub" navigation badge
     * re-evaluates its unread count without a page reload. The bell polls
     * every 10s (AdminPanelProvider), which caps how stale that badge can get.
     * Dispatching from render is safe here: the event targets the Sidebar
     * component, which never dispatches back to the bell.
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        $this->dispatch('refresh-sidebar');

        return parent::render();
    }
}
