<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Arr;

/**
 * Full-history notification hub for admins.
 *
 * The bell only shows the latest rows and treats dismiss as deletion — it is
 * no place to audit what happened. This page lists every Filament database
 * notification for the current admin (read, unread and resolved alike), lets
 * the admin mark rows read/unread (per row or all), and keeps the actionable
 * "Resend receipt" link available until a row is resolved. Rows are never
 * deletable here: the notification history is the audit trail, and resolution
 * state (`data.resolved_at`, written by ResendReceiptController) is the only
 * way a row stops needing action.
 */
class NotificationHub extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notification Hub';

    protected static ?string $title = 'Notification Hub';

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament-panels::pages.page';

    /**
     * Unread count shown on the sidebar "Notification Hub" item so the hub is
     * discoverable even when the topbar bell is out of view. Null when there
     * is nothing unread — no badge. The count is recomputed on every
     * navigation render; trivial at this scale.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::unreadCountForCurrentUser();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return static::unreadCountForCurrentUser() > 0 ? 'danger' : null;
    }

    /**
     * Unread Filament-format notifications for the current admin. Returns 0
     * when there is no authenticated admin (e.g. console/CLI renders) so the
     * navigation badge never crashes outside a request context.
     */
    public static function unreadCountForCurrentUser(): int
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return 0;
        }

        return DatabaseNotification::query()
            ->where('notifiable_id', $user->getKey())
            ->where('notifiable_type', $user->getMorphClass())
            ->where('data->format', 'filament')
            ->unread()
            ->count();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->notificationsQuery())
            ->columns([
                TextColumn::make('title')
                    ->label('Notification')
                    ->getStateUsing(fn (DatabaseNotification $record): string => (string) Arr::get($record->data, 'title', ''))
                    ->badge()
                    ->color(fn (DatabaseNotification $record): string => (string) (Arr::get($record->data, 'status') ?? Arr::get($record->data, 'color') ?? 'gray'))
                    ->description(fn (DatabaseNotification $record): ?string => Arr::get($record->data, 'body'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $escaped = addcslashes($search, '\\%_');

                        return $query->where('data->title', 'ilike', '%'.$escaped.'%')
                            ->orWhere('data->body', 'ilike', '%'.$escaped.'%');
                    })
                    ->wrap(),

                TextColumn::make('read_at')
                    ->label('Read')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? 'Read' : 'Unread')
                    ->color(fn (?string $state): string => $state ? 'gray' : 'primary'),

                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->getStateUsing(function (DatabaseNotification $record): string {
                        if (! empty(Arr::get($record->data, 'resolved_at'))) {
                            return 'Resolved';
                        }

                        return Arr::get($record->data, 'actions.0.url') ? 'Action needed' : 'Info';
                    })
                    ->color(function (DatabaseNotification $record): string {
                        if (! empty(Arr::get($record->data, 'resolved_at'))) {
                            return 'success';
                        }

                        return Arr::get($record->data, 'actions.0.url') ? 'danger' : 'gray';
                    }),

                TextColumn::make('action')
                    ->label('Action')
                    ->getStateUsing(fn (DatabaseNotification $record): string => (string) (Arr::get($record->data, 'actions.0.label') ?? '—'))
                    ->url(fn (DatabaseNotification $record): ?string => $this->actionUrlFor($record))
                    ->color('primary'),

                TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options([
                        'unread' => 'Unread',
                        'read' => 'Read',
                        'resolved' => 'Resolved',
                        'action_needed' => 'Action needed',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'unread' => $query->whereNull('read_at'),
                            'read' => $query->whereNotNull('read_at'),
                            'resolved' => $query->whereNotNull('data->resolved_at'),
                            'action_needed' => $query->whereNotNull('data->actions->0->url'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Action::make('markAsRead')
                    ->label('Mark as read')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (DatabaseNotification $record): bool => $record->read_at === null)
                    ->action(function (DatabaseNotification $record): mixed {
                        $result = $record->update(['read_at' => now()]);
                        $this->refreshBadges();

                        return $result;
                    }),

                Action::make('markAsUnread')
                    ->label('Mark as unread')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (DatabaseNotification $record): bool => $record->read_at !== null)
                    ->action(function (DatabaseNotification $record): mixed {
                        $result = $record->update(['read_at' => null]);
                        $this->refreshBadges();

                        return $result;
                    }),
            ])
            ->poll('10s')
            ->defaultSort('created_at', 'desc');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refreshBadges()),
            Action::make('markAllAsRead')
                ->label('Mark all as read')
                ->icon('heroicon-o-check')
                ->action('markAllNotificationsAsRead')
                ->visible(fn (): bool => $this->notificationsQuery()->unread()->exists()),
        ];
    }

    public function markAllNotificationsAsRead(): void
    {
        $this->notificationsQuery()->unread()->update(['read_at' => now()]);
        $this->refreshBadges();
    }

    /**
     * The sidebar "Notification Hub" badge (`getNavigationBadge()`) only
     * re-evaluates when Filament's Sidebar Livewire component re-renders, and
     * the topbar bell polls at best every 30s. Both components listen for
     * events (`refresh-sidebar` / `databaseNotificationsSent`); without them
     * the counts stay stale until a full page reload. Dispatching both is a
     * no-op on pages where either component is absent.
     */
    protected function refreshBadges(): void
    {
        $this->dispatch('refresh-sidebar');
        $this->dispatch('databaseNotificationsSent');
    }

    /**
     * Current-host resend URL for a row, so the action always works no matter
     * what host the stored payload was created under.
     *
     * 1. `data.payment_id` (tagged rows) → rebuild the route for this host.
     * 2. Otherwise a relative action path (new rows store the route as a path
     *    suffix, host-independent) → render path relative to current origin.
     * 3. Otherwise an absolute legacy URL → rebuild from the payment id inside
     *    it (covers pre-tag rows whose host no longer matches APP_URL).
     * 4. Unparseable / not a resend action → null, so the label renders as
     *    plain text instead of a dead link.
     */
    private function actionUrlFor(DatabaseNotification $record): ?string
    {
        $paymentId = Arr::get($record->data, 'payment_id');

        if (is_numeric($paymentId)) {
            return (string) route('admin.payments.resend-receipt', (int) $paymentId);
        }

        $url = Arr::get($record->data, 'actions.0.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (preg_match('#/payments/(\d+)/resend-receipt$#', $url, $matches) === 1) {
            return (string) route('admin.payments.resend-receipt', (int) $matches[1]);
        }

        return null;
    }

    /**
     * All Filament database notifications for the current admin. Unreachable
     * without an authenticated admin (panel auth guards the route), so the
     * null case only exists for static-analysis completeness.
     */
    private function notificationsQuery(): Builder
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        if ($user === null) {
            return DatabaseNotification::query()->whereRaw('1 = 0');
        }

        return DatabaseNotification::query()
            ->where('notifiable_id', $user->getKey())
            ->where('notifiable_type', $user->getMorphClass())
            ->where('data->format', 'filament');
    }
}
