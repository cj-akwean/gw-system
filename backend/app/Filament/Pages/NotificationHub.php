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

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->notificationsQuery())
            ->columns([
                TextColumn::make('title')
                    ->label('Notification')
                    ->getStateUsing(fn (DatabaseNotification $record): string => (string) Arr::get($record->data, 'title', ''))
                    ->badge()
                    ->color(fn (DatabaseNotification $record): string => (string) (Arr::get($record->data, 'color') ?? 'gray'))
                    ->description(fn (DatabaseNotification $record): ?string => Arr::get($record->data, 'body'))
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
                    ->url(fn (DatabaseNotification $record): ?string => Arr::get($record->data, 'actions.0.url'))
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
                    ->action(fn (DatabaseNotification $record): mixed => $record->update(['read_at' => now()])),

                Action::make('markAsUnread')
                    ->label('Mark as unread')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (DatabaseNotification $record): bool => $record->read_at !== null)
                    ->action(fn (DatabaseNotification $record): mixed => $record->update(['read_at' => null])),
            ])
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
