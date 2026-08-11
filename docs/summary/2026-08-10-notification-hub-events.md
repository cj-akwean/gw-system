# 2026-08-10 — Notification hub events (AdminNotifier) + mail-delivery diagnosis

## Goal

Make the admin Notification Hub a real activity log. Previously only *email failures*
wrote persistent entries (payment + identifier-change mail); everything else (billing
runs completing, payments received, CSV imports) was a 5-second toast or nothing at all.
Also: diagnosed why the payment-confirmation email silently "didn't send".

## Part 1 — Mail delivery diagnosis (root cause found)

- Symptom: `MAIL_MAILER=smtp` in `.env` but the payment email never arrived; no admin
  bell either.
- Evidence: laravel.log at 20:24:33 showed the **log-mailer dump** (`local.DEBUG: From:
  … Subject: Payment received …`) — the mail went to `storage/logs`, not SMTP. `.env`
  had been changed 7 min earlier (20:17).
- Root cause: `composer run dev` (user's own command) spawns `queue:listen` which
  launches each job as `php artisan queue:work --once` with the **inherited parent
  environment** (`Process($cmd, path, null, null)` in `Listener::makeProcess`), and
  Laravel's `.env` is **immutable** — a real env var (`MAIL_MAILER=log`, left over in
  the launching terminal session from when mail was disabled) overrides `.env` forever.
  Every job went through the log mailer; the log mailer "succeeds" by writing a file,
  so the `failed()` hook never fired → no hub notification. Lost mail ≠ failed mail.
- Fix applied: killed both stale `composer run dev` trees, started one fresh from a
  clean session (cmd PID 12564, logs → `backend/storage/logs/queue-worker.log`);
  dispatched the real `SendPaymentConfirmationEmail` job for GW-2026-00048 at 21:09 →
  processed 21:09:26, no log dump → went out via SMTP (Mailtrap).
- User guidance written: diagnose with (1) `config('mail.default')` via tinker,
  (2) laravel.log for `local.DEBUG: From:` (log mailer active), (3) paymongo.log
  `sent`/`skipped` lines, (4) `failed_jobs`. Restart dev from a FRESH terminal window
  after any `.env` change; `$env:MAIL_MAILER` must be empty in the launcher.

## Part 2 — Notification hub events (user request: hub should store admin notifs)

### Files created / modified

| File | Action | What |
|---|---|---|
| `backend/app/Support/AdminNotifier.php` | new | `notify(title, body, color='info', ?actionLabel, ?actionPath, ?actionName)` → Filament DB notification to all `is_admin` users (no-op when none); returns the admins collection; paths stored host-independent; default action name `view`, overridable (`resendReceipt`) |
| `backend/app/Jobs/RunBillingJob.php` | modified | Notifications at 4 points: completed (success, invoice count + ₱ total, **View run** action), failed (danger + **View run**), mismatched-period / superseded / force-failed-not-resumed (warning) |
| `backend/app/Jobs/SendPaymentConfirmationEmail.php` | modified | `handle()` adds info "Receipt sent" (invoice #, recipient count) after a successful send; `failed()` refactored onto `AdminNotifier` (keeps `resendReceipt` action name + tagging) |
| `backend/app/Jobs/SendConnectionIdentifierChangedEmail.php` | modified | `failed()` refactored onto `AdminNotifier` |
| `backend/app/Jobs/ProcessPayMongoWebhook.php` | modified | After the outer transaction, when `markPaidFromWebhook` actually returned a Payment (null on already-paid/skip) → info "Payment received" (invoice, ₱ amount, channel label). Replay-safe: dedupe row is in the same transaction |
| `backend/app/Filament/Resources/MeterReadingResource/Pages/ImportMeterReadings.php` | modified | After import: hub entry (info/warning) with imported/failed counts, toast kept for initiator |
| `backend/app/Filament/Resources/ServiceConnectionResource/Pages/ImportServiceConnections.php` | modified | Same pattern |
| `backend/app/Filament/Pages/NotificationHub.php` | modified | Badge color reads `data.status` (Filament stores severity there; `data.color` is null → everything rendered gray before) with `data.color` fallback |

### Tests

- `RunBillingJobTest` +3: completed → success row w/ `data->actions->0->url`
  `/admin/billing-runs/{id}` + only admins; failed → `data->status danger`; superseded →
  warning.
- `SendPaymentConfirmationEmailTest` +1 (success → "Receipt sent" for admins only) and
  skip path asserts `notifications` count 0.
- `ProcessPayMongoWebhookTest` +1: paid → one "Payment received" row; replay of the same
  event → still exactly one.
- `ImportServiceConnectionsPageTest`: existing success test now also asserts the hub row.
- `ResendReceiptControllerTest`: 2 tests made row-targeting explicit (resend now also
  creates a "Receipt sent" entry, so `notifications()->first()` was no longer the
  resolved row); action-name assertions now pass via the `actionName` param.

### Test results (actually verified)

- Touched filters: 66/66 + 57/57 (ResendReceiptController/ServiceConnectionResource/
  BillingRunResource) green; `php -l` clean on every changed file.

### Bugs found during implementation

1. **`data.color` doesn't exist in Filament notifications** — severity lives in
   `data.status`. My first test asserts on `data->color` failed; the dump showed
   `"status":"danger","color":null`. Also fixed the hub's badge color (was gray for
   every row).
2. **Action name regression:** `AdminNotifier` hardcoded `Action::make('view')`; the
   resend tests assert `data.actions[0].name === 'resendReceipt'`. Added `actionName`
   param.
3. **Resend now writes a new "Receipt sent" row** — correct behavior, but two
   `ResendReceiptControllerTest` assertions read `notifications()->first()` (order
   undefined) — made them target `data->title = 'Payment confirmation email resent'`.
4. PsySH/`tinker --execute` on Windows mangles quoting (T_PRIVATE on `storage_path
   ("app/private/…")`, truncation) — use a temp file piped into `php artisan tinker`
   (no `<?php` tag).

## Known gaps / next step

- Not verified live: actual hub rows after a real billing run / webhook payment /
  import in the browser (dev server was restarted; `php artisan queue:work` rule:
  restart worker after code changes — the running `composer run dev` predates these job
  edits, so restart it before exercising the flows).
- `paymongo:reconcile` anomalies still log-only (no hub entry) — candidate for a future
  event.

## Git

Not committed.
