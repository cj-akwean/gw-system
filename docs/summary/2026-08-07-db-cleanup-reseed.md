# 2026-08-07 — DB cleanup + fresh demo reseed

## Goal

Wipe accumulated phase-testing data (billing runs, webhook events, payments, invoices,
readings, links, connections, reference data, tokens, failed jobs) while keeping the two
`users` rows, then fix the seeder so the DB is coherent and fresh for the next phases
(portal shell testing, Payment Method).

## Files created or modified

- `backend/database/seeders/DemoPortalDataSeeder.php` (new) — demo portal state for
  `test@example.com`: active link to connection #1, three consecutive meter readings,
  three invoices (paid / overdue / unpaid) with amounts derived from the flat rate
  (₱10/m³), plus an offline `Payment` row for the paid invoice. Guards on existing links
  so re-seeding is idempotent.
- `backend/database/seeders/DatabaseSeeder.php` — `test@example.com` now gets a real
  password (`password`); both demo users switched from `firstOrCreate` to `updateOrCreate`
  so re-seeding always resets to the documented credentials; calls
  `DemoPortalDataSeeder`.
- `AGENTS.md` — credentials table now includes the portal test user row + reseed note.

## Data changes (manual cleanup, users kept)

Deleted in FK-safe order: payments (8), processed_webhook_events (6), invoices (9),
billing_runs (12), connection_links (5), meter_readings (19), service_connections (15),
rate_schedules/penalty_rules (incl. one stale penalty rule), barangays (15),
personal_access_tokens (19 — invalidates old sessions/tokens), failed_jobs (2), cache.
Kept: users (2), sessions (3). Postgres sequences are NOT reset — new row IDs continue
(e.g. connection #19, invoice #10–12).

## Verification

- `php artisan db:seed --force` clean. After: 2 users, 15 barangays, 1 rate schedule,
  1 penalty rule, 15 connections, 3 readings, 1 active link, 3 invoices, 1 payment,
  0 billing runs, 0 webhook events.
- `Hash::check('password')` true for test@example.com; `admin123` still works for admin.
- `PortalBillsService::unpaidInvoices(test user)` → 2 bills (GW-2026-00002 overdue
  ₱438.60, GW-2026-00003 unpaid ₱320.00), overdue first — matches the portal shell order.
- No test suite covers seeders (grep: zero test references to DatabaseSeeder) — verified
  directly instead. Backend suite unaffected (no app code changed).

## Known gaps / next step

- Old Next.js `localStorage` tokens (e.g. open tabs) now 401 → portal auto-logout +
  redirect; re-login with test@example.com / password.
- Next unchecked checklist item: Payment Method.

## Commit

- Not committed; no commit hash.
