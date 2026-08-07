# 2026-08-07 — Notification hub UI (Phase 2: bell non-destructive + full history page)

## Goal
Close the user-confirmed gap (2026-08-06): the bell only listed unread notifications and
treated dismiss as deletion — no history anywhere. Add a Notification Hub page listing every
Filament database notification for the admin (read/unread/resolved), and make bell dismissal
non-destructive (mark read, never delete).

## What was built

- `backend/app/Livewire/AdminDatabaseNotifications.php` — subclass of the stock
  `Filament\Notifications\Livewire\DatabaseNotifications`. Re-declares the Livewire v3
  `#[On('notificationClosed')]` handler `removeNotification(string|array)` to set
  `read_at = now()` instead of deleting (uuid-guarded, idempotent), and hides the stock
  "Clear all" header action via `parent::clearNotificationsAction()->hidden()`.
- `backend/app/Providers/Filament/AdminPanelProvider.php` (line 35) — registered it with
  `->databaseNotifications(livewireComponent: AdminDatabaseNotifications::class)`.
- `backend/app/Filament/Pages/NotificationHub.php` — `Page implements HasTable`, slug
  `/admin/notifications`, sidebar nav entry. Table columns: notification title (badged with
  `data.color`, body as description), read/unread badge (from `read_at`), State badge
  (Resolved from `data.resolved_at`, else "Action needed" if `data.actions.0.url` present,
  else Info), the actionable "Resend receipt" link from `data.actions.0` (survives until
  resolution), received datetime (sortable, default desc). Select filter: unread/read/
  resolved/action-needed. Row actions mark-as-read / mark-as-unread (mutually visible).
  Header action "Mark all as read" (only shown when unread rows exist) →
  `markAllNotificationsAsRead()` bulk-updates `whereNull('read_at')`.
  **No delete anywhere** — rows are the audit trail.
- `notificationsQuery()` — `DatabaseNotification::query()->where('notifiable_id', …)->where(
  'notifiable_type', …)->where('data->format', 'filament')` — written directly on the model
  (not `$user->notifications()`) to satisfy `Filament\Tables\Table::query()` typing.

## Bugs found & fixed (root causes)

- **Table didn't render on the page.** Page rendered header-only; Livewire snapshot showed
  `isTableLoaded: false` and no `table.records.<uuid>` keys. Root cause: in Filament v5 the
  table is a `ViewComponent` — a `Page implements HasTable` must explicitly embed it in
  `content(Schema $schema)` via `EmbeddedTable::make()` (exactly what `ListRecords` does).
  `assertCanSeeTableRecords` and `assertSee(<cell text>)` then started passing.
- **Header action test couldn't fire.** `->callAction('markAllAsRead')` did nothing because
  the button's `wire:click` is the bare method name (`->action('markAllNotificationsAsRead')`
  is a string action → `getLivewireEventClickHandler()` returns the method directly). Test
  now uses `->call('markAllNotificationsAsRead')`.
- **Non-admin got 403, test expected redirect.** Filament panel auth returns HTTP 403 for an
  authenticated-but-unauthorized user; test changed to `assertForbidden()`.

## Test results (verified)
- New `tests/Feature/NotificationHubTest.php` (16) + `tests/Feature/AdminDatabaseNotificationsTest.php` (5): 21/21 green
  (final count 20 after one test consolidated). Covers guest redirect, non-admin 403,
  read/unread/resolved rendering, resolved-but-unread truthfulness, per-row read/unread,
  mark-all scoped to own unread rows only, action link visibility, missing-actions-key
  safety, bell dismiss = mark-read, uuid noop, idempotent dismiss, clear hidden, regressions.
  Wait — final combined file run: **20 tests, 20 passed, 45 assertions**.
- **Full suite: 442 passed / 1,556 assertions** (`php -d memory_limit=512M
  vendor/phpunit/phpunit/phpunit`). Pint applied to the 5 touched files (re-verified 20/20
  after formatting).

## Known gaps / next step
- Bell poll interval/list query unchanged (stock 30 s); hub doesn't poll — refresh on load.
- Legacy untagged rows rely on `data.actions.0.url` fallback for the Action column (pre-existing).
- Next unchecked item in `ARCHITECTURE.md` → Notifications: **SMS notifications wired up**
  (Semaphore/Twilio, optional/later).