<?php

namespace App\Support;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Sends a persistent Filament database notification to every admin user —
 * the feed behind the Notification Hub. Toasts are ephemeral; anything worth
 * auditing later (billing runs, payments, receipts, imports, failures) goes
 * through this helper so the hub stays the single place admins look for what
 * happened. No-op when no admin exists.
 *
 * Action URLs must be host-independent path suffixes (e.g. `/admin/billing-runs/3`)
 * so the stored rows resolve on any host the hub is read back from — the same
 * convention the resend notifications use.
 */
class AdminNotifier
{
    /**
     * @return Collection<int, User> the admins notified (empty when none exist)
     */
    public static function notify(
        string $title,
        string $body,
        string $color = 'info',
        ?string $actionLabel = null,
        ?string $actionPath = null,
        ?string $actionName = null,
    ): Collection {
        $admins = User::query()->where('is_admin', true)->get();

        if ($admins->isEmpty()) {
            return $admins;
        }

        $notification = match ($color) {
            'success' => Notification::make()->title($title)->body($body)->success(),
            'warning' => Notification::make()->title($title)->body($body)->warning(),
            'danger' => Notification::make()->title($title)->body($body)->danger(),
            default => Notification::make()->title($title)->body($body)->info(),
        };

        if ($actionLabel !== null && $actionPath !== null) {
            $notification->actions([
                \Filament\Actions\Action::make($actionName ?? 'view')
                    ->label($actionLabel)
                    ->button()
                    ->color('primary')
                    ->url($actionPath),
            ]);
        }

        $notification->sendToDatabase($admins);

        return $admins;
    }
}
