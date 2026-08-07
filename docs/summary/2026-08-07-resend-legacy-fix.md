# 2026-08-07 — Resend-receipt legacy fix: host-agnostic notification matching + broken-row purge

## Goal
User-reproduced bug: the "Payment confirmation email failed" notification for
GW-2026-00008 (payment #8) was stuck as **Action needed** forever even though the
receipt had already been resent successfully on the XAMPP/127.0.0.1 instance. Because
the Notification Hub (built earlier the same day) never deletes rows, the broken row
was unfixable through the UI — user asked to just delete it.

## Root cause (confirmed against the live DB)
- The failure notification's stored action URL was `http://localhost/admin/payments/8/resend-receipt`
  (host `localhost`, port 80), but the running app uses `APP_URL=http://127.0.0.1:8000`.
- Both `tagNotifications` (job) and `ResendReceiptController::notificationsFor` matched
  notifications by **exact absolute-URL equality** against today's `route(...)`. Neither
  matched this row → it never got tagged (`data.payment_id` NULL, verified via psql) and
  could not be resolved.
- The resend route still sent the email (matched **zero** rows → send proceeded, nothing
  resolved), so "resend worked" while the row stayed broken — and the hub's existing
  "Resend receipt" link pointed at the dead port-80 host.

## Files modified
- `backend/app/Jobs/SendPaymentConfirmationEmail.php` — failure notification now stores the
  action URL as a **relative route path** (`resendPath()`, host-independent). `tagNotifications`
  matches by path **suffix** (`LIKE '%<path>'`) instead of exact-URL equality, so rows created
  under any host still get tagged. Untouched: tag idempotency guard `whereNull('data->payment_id')`.
- `backend/app/Http/Controllers/Admin/ResendReceiptController.php` —
  1. `notificationsFor()` scoped to `data->format='filament'` + matches `payment_id` OR URL
     path-suffix (host-agnostic legacy fallback).
  2. Fixed **`'already'` short-circuit bug**: a single resolved copy used to abort the whole
     resend and leave other copies stuck. Now resolves **per pending row**; `already` only
     fires when rows exist but none are pending. No matched rows → still sends (no regression
     for rows without a notification).
  3. Resolution now stamps `payment_id`/`invoice_id` back onto rows — required for idempotency,
     because resolution wipes `actions`, so path/URL matching can't find a resolved row again;
     without the stamp a second click on a path-resolved legacy row would duplicate the email.
- `backend/app/Filament/Pages/NotificationHub.php` — action link now derived for the
  **current host** (`actionUrlFor()`): `data.payment_id` → current route; else relative path;
  else rebuild from a `/payments/{id}/…` pattern inside a legacy absolute URL; else **no href**
  (plain text — never renders a dead foreign-host link).
- `backend/tests/Feature/ResendReceiptControllerTest.php` —
  - updated `test_failed_job_notification_carries…_route_path` (URL now relative path);
  - added: foreign-host legacy URL resolved; mixed resolved+unresolved copies → only pending
    resolved + second click idempotent; non-filament row sharing `payment_id` never touched.
- `backend/tests/Feature/NotificationHubTest.php` — added: link rebuilt from `payment_id`
  (foreign stored URL not rendered); path fallback without payment id; unparseable URL renders
  label with no href.
- `ARCHITECTURE.md` — new checklist item for host-agnostic tagging/resolution; updated dev gotcha
  note + legacy-fallback note.
- `docs/insights/product-decisions.md` — **#32: never match notifications by absolute URL**;
  tag by payment id, match by path suffix, stamp resolved rows (with the incident analysis).

## Data fix (not code)
Purged one orphan row from dev DB (`DELETE … WHERE id='f71b6b03-6d84-4eef-8c5f-f404ff096df6'
AND data->>title='Payment confirmation email failed' AND data->>payment_id IS NULL`). After:
`SELECT count(*) FROM notifications` → 0.

## Test results (verified)
- `php -l` clean on all 5 changed files.
- Targeted: ResendReceiptControllerTest + NotificationHubTest — **34 passed / 120 assertions**.
- Bell + job tests: **20 passed / 44 assertions**.
- Full suite: **448 passed / 1,584 assertions** (up from 442 — 6 new tests).

## Follow-up (same session): invisible bell + hub nav badge
- **Bug:** after the Notification Hub commit, the topbar bell disappeared entirely. Root cause: the
  custom `AdminDatabaseNotifications` extended the base `Filament\Notifications\Livewire\DatabaseNotifications`,
  whose `getTrigger()` returns `null` — a null trigger renders the modals with no button, so no bell icon
  showed. The panel default uses `Filament\Livewire\DatabaseNotifications` (panel-aware, returns the
  topbar/sidebar trigger). Fixed by extending that class instead; dismiss/clear behavior unchanged.
- **Feature:** `NotificationHub` now shows an unread-count badge on its sidebar item via
  `getNavigationBadge()`/`getNavigationBadgeColor()` (`danger`), guarded for no-auth (0 / null).
- Tests added: bell trigger renders (`fi-topbar-database-notifications-btn`) at component level and on
  `/admin`; hub nav badge counts only own unread Filament rows and clears when read.
- Verified: `php -l` clean; **full suite 453 passed / 1,592 assertions** (up from 448 — 5 new tests).

## Known gaps / next step
- Hub still never deletes (decision #31) — genuinely-broken rows are purged via SQL/artisan as
  an ops action. Revisit only if it recurs in prod.
- Bell poll interval unchanged (30 s stock).
- Next unchecked `ARCHITECTURE.md` item → Notifications: **SMS notifications wired up**
  (Semaphore/Twilio, optional/later).
- Commit not yet created (awaiting user approval); commit hash to be backfilled once committed.