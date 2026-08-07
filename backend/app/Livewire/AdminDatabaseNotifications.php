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
    #[On('notificationClosed')]
    public function removeNotification(string|array $payload): void
    {
        $id = is_array($payload) ? ($payload['id'] ?? null) : $payload;

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
}
