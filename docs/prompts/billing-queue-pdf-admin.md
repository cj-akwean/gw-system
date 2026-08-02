# Prompt: Billing — Queued Job + PDF + Admin UI (next phases)

> Copy-paste this whole file into the next session. Read `docs/summary/2026-08-02-billing-service.md`,
> `docs/insights/product-decisions.md` (§11 = the billing decisions), and ARCHITECTURE.md's
> Billing section first. Do phases in order; one phase per session if needed (AGENTS.md rule).

## Context

GW-System (Laravel 13 + Filament 5 admin, Postgres dev = prod). **Billing checklist item 1
is DONE**: `App\Services\BillingService` with `php artisan billing:run` for manual runs.

Current behavior (all verified by tests, 50/50 suite green):
- **Run** (`BillingService::run(?string $periodEnd)`): period end defaults to end of last
  month; window = `[period_end − 30 days, period_end]`. For each **active** connection:
  latest reading in window → if none: `skipped` "No reading in the billing period"; if
  flagged ≠ 0: `skipped` "Flagged reading (level N) — investigate, then bill manually"
  (negative `cu_m_used` can never feed math); if already invoiced for that reading:
  `skipped` "Already billed" (idempotent); if no effective schedule: `skipped`. Else
  `billConnection()` → returns a per-connection report Collection.
- **Math**: `computeBaseAmount` (flat: usage × rate; tiered: walks RateTier blocks),
  `findEffectiveSchedule` (connection's schedule if effective, else global active),
  `computePenalty` (2%/month on unpaid total, starts after due date + grace, full 30-day
  buckets), `computePreviousBalance`-equivalent inline in `billConnection`
  (`previous_balance` = sum of unpaid totals, `penalty_amount` = accrued, `base_amount` =
  usage, total = all three; due_date = period end + grace days).
- Run marks unpaid invoices past period end as `overdue`.
- Invoice numbering: `GW-YYYY-XXXXX`, max+1 (see `generateInvoiceNumber()`).
- Rate assignment: nullable `rate_schedule_id` on `service_connections` (fallback to global
  active schedule). Seeded: one flat ₱10/cu.m. schedule assigned to all connections.
- Composite index `meter_readings (service_connection_id, entered_at)` exists.

Key files:
- `backend/app/Services/BillingService.php`
- `backend/app/Console/Commands/BillingRunCommand.php`
- `backend/tests/Feature/BillingServiceTest.php` (17 tests)
- `backend/app/Models/Invoice.php`, `RateSchedule.php`, `RateTier.php`, `PenaltyRule.php`
- `backend/database/seeders/RateScheduleSeeder.php`

## Phase 1 — Queued billing job (checklist item 2)

1. New `App\Jobs\RunBillingJob` that calls `BillingService::run()` (with optional period).
   `billing:run` command becomes: dispatch job with `--sync` option for immediate run
   (tests/manual) vs queued (default). Keep the report printing: either the job stores its
   report (cache/DB column) or the command stays synchronous-only and a separate
   `billing:run --queue` option dispatches without output.
2. Queue driver is `database` — worker: `php artisan queue:work` (document; no systemd
   setup yet — Infra checklist "Queue worker running" stays unchecked until then).
3. Tests: assert the job is dispatched from the command and that the job produces invoices
   when run through the queue (QUEUE_CONNECTION=sync in tests).
4. Update ARCHITECTURE.md checkbox + this prompt.

## Phase 2 — Invoice PDF (checklist item 3)

5. dompdf (barryvdh/laravel-dompdf) is already in composer.json per Key Packages. Generate
   the invoice PDF **in memory** from the Invoice row + relations (service connection,
   rate schedule, reading): itemized breakdown matching real PH water bills — current
   charges (base_amount), arrears (previous_balance), penalty (penalty_amount), total,
   due date, account + meter numbers. **No permanent file storage** (AGENTS.md).
6. Emailing on payment is a Payments-phase task — PDF generation itself goes in a
   `App\Services\PdfService` (business logic stays in services, never Filament).
7. Add a `billing:pdf {invoice-number}` command (or similar) for manual verification +
   a test asserting the PDF string starts with `%PDF`.

## Phase 3 — Admin billing views (Admin Panel checklist)

8. Filament resource for Invoices (list by status, view detail with breakdown, mark paid —
   ties into the "Record offline/manual payments" Payments item) and a "Run billing"
   action page that shows the report from the last queued run.
9. Only after Phases 1–2 are verified end-to-end with the user's manual pass.

## NOT in scope here

- PayMongo integration/webhook (Payments phase, separate checklist).
- Residential/commercial rate classes + minimum charge (Deferred — see ARCHITECTURE.md).
