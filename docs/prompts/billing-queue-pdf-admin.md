# Prompt: Billing — Queued Job + PDF + Admin UI (next phases)

> **Status: Phase 1 (queued job) is DONE (2026-08-03, committed as part of checklist
> item 2).** Phase 2 (PDF) is DONE (2026-08-03: `PdfService`, `billing:pdf` command,
> `pdfs.invoice` view, PdfServiceTest 5/5 — see decision 25 in `billing-decisions.md`
> Part 4). This file now describes Phase 3 (admin UI). Read
> `docs/summary/2026-08-03-billing-queue.md` for what Phase 1 actually built and verified.
> Copy-paste this whole file into the next session. Read `docs/summary/2026-08-02-billing-service.md`,
> `docs/insights/product-decisions.md` (§11 = the billing decisions), and ARCHITECTURE.md's
> Billing section first. Do phases in order; one phase per session if needed (AGENTS.md rule).

## Context

GW-System (Laravel 13 + Filament 5 admin, Postgres dev = prod). **Billing checklist item 1
is DONE**: `App\Services\BillingService` with `php artisan billing:run` for manual runs.
**Checklist item 2 (queued job) is DONE too**: `billing:run` dispatches `RunBillingJob` by
default (`--sync` for inline), every run records to `billing_runs` (status + JSON report),
`billing:report {id}` prints stored reports, and a Postgres partial unique index blocks
concurrent runs per period.

Current behavior (all verified by tests, 72/72 suite green):
- **Run** (`BillingService::run(?string $periodEnd)`): period end defaults to end of last
  month; window = the exact calendar month of `period_end`. For each **active** connection:
  latest reading in window → if none: `skipped` "No reading in the billing period"; if
  flagged ≠ 0: `skipped` "Flagged reading (level N) — investigate, then bill manually"
  (negative `cu_m_used` can never feed math); if already invoiced for that reading:
  `skipped` "Already billed" (idempotent); if no effective schedule: `skipped`. Else
  `billConnection()` → returns a per-connection report Collection.
- **Queue**: `RunBillingJob` (ShouldQueue, `$tries = 3`) calls `run()` and writes the
  report to its `billing_runs` row; failures mark the row `failed` + rethrow into
  `failed_jobs`. `billing:run` refuses to dispatch a second `running` run for the same
  period (exit 1). Scheduler wiring deferred to Infra phase.
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
- `backend/app/Jobs/RunBillingJob.php`
- `backend/app/Console/Commands/BillingRunCommand.php` (`--period`, `--sync`)
- `backend/app/Console/Commands/BillingReportCommand.php`
- `backend/app/Models/BillingRun.php` (+ migration `2026_08_03_000001`)
- `backend/tests/Feature/BillingServiceTest.php`, `backend/tests/Feature/RunBillingJobTest.php`
- `backend/app/Models/Invoice.php`, `RateSchedule.php`, `RateTier.php`, `PenaltyRule.php`
- `backend/database/seeders/RateScheduleSeeder.php`

## Phase 2 — Invoice PDF (checklist item 3) — DONE (2026-08-03)

5. dompdf (barryvdh/laravel-dompdf v3.1.2, already in composer.json) generates the
   invoice PDF **in memory** from the Invoice row + relations (service connection,
   rate schedule, reading): itemized breakdown matching real PH water bills — current
   charges (base_amount), arrears (previous_balance), penalty (penalty_amount), total,
   due date, account + meter numbers. **No permanent file storage** (business logic
   stays in `App\Services\PdfService`, not in Filament views).
6. Emailing on payment is a Payments-phase task — PDF generation itself lives in
   `App\Services\PdfService` (`generate()` returns raw dompdf bytes, reused by the
   Payments phase to email the attachment; `buildViewData()` does the date/rate math
   so the view stays pure presentation).
7. `billing:pdf {invoice-number} [--output=]` writes the PDF to the storage disk for
   manual verification (default `pdf-verification/<invoice_number>.pdf`). A Feature test
   (`PdfServiceTest.php`, 5/5) asserts the view renders every itemized field and that
   `generate()` output starts with `%PDF`.

## Phase 3 — Admin billing views (Admin Panel checklist)

8. Filament resource for Invoices (list by status, view detail with breakdown, mark paid —
   ties into the "Record offline/manual payments" Payments item) and a "Run billing"
   action page that reads the report from the `billing_runs` table (status + JSON report
   per run, already in place from Phase 1).
9. Only after Phases 1–2 are verified end-to-end with the user's manual pass.

## NOT in scope here

- PayMongo integration/webhook (Payments phase, separate checklist).
- Residential/commercial rate classes + minimum charge (Deferred — see ARCHITECTURE.md).
