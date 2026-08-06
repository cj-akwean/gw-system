# GW-System Architecture

## Directory Structure

```
gw-system/
  frontend/     ← Next.js + shadcn (static marketing + customer portal)
  backend/      ← Laravel 13 + Postgres (API + Filament admin)
  ARCHITECTURE.md
  AGENTS.md
```

## Tech Stack

| Layer | Technology |
|---|---|
| Customer-facing UI | Next.js 16 + React 19 + shadcn/ui + Tailwind v4 |
| Admin Panel | Filament (PHP/Livewire) |
| Backend Framework | Laravel 13 |
| Database | Postgres (both dev & prod) |
| API Auth | Laravel Sanctum — Bearer token mode (not SPA cookies) |
| Payments | **PayMongo** (one-off payments, not subscriptions) |
| Invoices | Generate PDF via barryvdh/laravel-dompdf, email to customer |
| Queue | Laravel Queues (database driver) for billing runs, SMS, PDF generation |
| File Storage | None permanent — PDFs generated in-memory and emailed; regenerate from DB on demand |
| Email (prod) | Resend |
| Email (dev/test) | Mailtrap |
| SMS | Semaphore (PH) or Twilio (global) |
| Exports / Reports | Laravel Excel |
| Timezone | Asia/Manila (`APP_TIMEZONE`, PHP time — no DST in PH; decided 2026-08-05, see product-decisions §17) |
| Codebase context | Graphify knowledge graph (`graphify-out/`) — query before editing shared code; `.graphifyignore` excludes `backend/vendor` + `backend/public/js`, so the graph covers project code only (rebuild done 2026-08-01, ~2,135 nodes) |

## Architecture

```
Frontend (Next.js)                Backend (Laravel)
────────────────────              ──────────────────
Marketing pages  ──API calls──▶   Regular API routes
Customer portal                    (auth, billing, payments, readings)
  (Bearer token auth)               │
Admin Panel (Filament) ───────────── same DB, same models
(CRM, dashboard, billing mgmt)      (Livewire — no API needed)
                                    │
Payment Gateway (PayMongo) ───────── webhook → marks invoice paid
```

- **Next.js frontend** stays as-is for marketing pages. Customer portal features (pay bills, view usage) added later via API calls using Sanctum Bearer tokens.
- **Filament admin** provides CRM, dashboard, billing management at `/admin` — separate auth guard from API, rate-limited differently.
- **PayMongo webhooks** notify Laravel when a payment succeeds → invoice marked paid, PDF generated and emailed.
- **Business logic** lives in `App\Services\*` Service classes, never in Filament Resources.

## Database

- Postgres for both dev and prod (avoid SQLite dev / MySQL prod surprises)
- Local dev: **native PostgreSQL 18 install** (via winget on Windows) — no Docker/Sail needed
- Postgres is stricter about constraints — catches bugs earlier for a billing system where data integrity matters

## Queue & Background Jobs

- Billing runs, SMS blasts, PDF generation, bulk exports → Laravel Queues
- Start with `database` driver (no extra service needed)
- Prevents request timeouts during bulk operations
- Dev worker: `php artisan queue:work --tries=3` (or `--once` to process a single job and exit); failed jobs land in `failed_jobs` — check with `php artisan queue:failed`
- The monthly billing run is dispatched by `billing:run` (queued by default) — see Billing section; automatic scheduling deferred to Infra (needs cron + a running worker on the host)

## Meter Readings

- Meter reading stays a **physical, in-person process** (confirmed by real PH water bills — a reader walks the barangay and reads each meter by hand; there's no automated hardware). GW-System doesn't try to replace the walk — it replaces what happens *after* the walk.
- **CSV import** (bulk upload via Filament, one file per reading day/route) + **manual entry** (Filament form) for individual corrections
- No hardware/automated reading integration for now
- Audit trail for every reading entry (who entered it, when, method)
- Each reading stores `present_reading`, `previous_reading`, and computed `cu_m_used` — matches the exact fields printed on real water bills (present/previous/cu.m. used), so admin views map 1:1 to what a customer already recognizes from paper bills
- **Meter replacement**: when a physical meter is swapped, the new meter starts at 0, so a reading can legitimately be `present < previous` → negative `cu_m_used`. Such readings are stored with `flagged = 2` (auto-detected, never rejected). `previous_reading` and the flag level are recomputed at insert time against the actual latest reading — a reading that ends up lower than a just-imported previous (e.g. a later row in the same CSV file) is stored as level 2, never an unflagged negative. The chain self-corrects on the next reading (previous = new meter's value), but billing must handle the flagged reading (see Billing section). Flag levels: `0` = not flagged, `1` = flagged by CSV/manual with no automatic basis, `2` = auto-flagged (`present < previous`). Any non-zero level means "suspicious" for billing.
- **Minimum 30-day gap between readings**: a new reading is only accepted when its date is at least 30 days after the connection's last reading (monthly-billing cycle; exactly 30 days = allowed, sooner = hard-blocked). Future dates are rejected outright. First readings are exempt (no age limit). Applies to manual entry and CSV import alike. The gap is checked against the DB's latest reading only — rows inside the same CSV file don't affect each other's gap check on a first upload.
- **No duplicate reading dates**: one reading per connection per date, enforced three ways — import preview (DB + in-file checks), the manual form rule (Create and Edit), and a DB unique index on `(service_connection_id, entered_at::date)` that backstops every path including insert races.
- **CSV round-trip**: the importer reads an optional `flagged` column (`1/0`, `true/false`, `yes/no`; empty = not flagged; sets level 1 — the auto-detected `present < previous` level 2 always wins) and silently ignores any other extra columns. After preview, the full preview (valid **and** invalid rows) can be downloaded as CSV — the original columns plus `notes` (per-row errors / flag message) and `flagged` (`0/1/2`) — so rows can be fixed offline and re-imported; already-imported rows are caught by the DB duplicate check, never imported twice.

## Barangays

- `Barangay` is a simple lookup table (id, name) — seeded once, not free-typed by customers at signup
- Customer portal signup uses a barangay **dropdown**, not a text field, to avoid typos and keep zone data clean for reading routes / reports later
- Seed list (real Guinobatan, Albay barangays — 15 of the municipality's 44, more can be added anytime without a schema change): Poblacion, Mauraro, San Rafael, Masarawag, Maipon, Travesia, San Francisco, Quibongbongan, Calzada, Quitago, Morera, Muladbucad Grande, Binogsacan Lower, Maguiron, Lomacao

## Customer & Connection Linking (renter/boarder problem)

Real PH utility bills (electric co-op and municipal water alike) identify an account by **account number + meter number**, printed on the physical bill — completely independent of who currently lives there. GW-System mirrors this instead of inventing a new scheme:

- **`ServiceConnection`** is the source of truth for a physical connection: `account_number`, `meter_number`, `registered_name` (whoever originally applied — stays fixed, historical/legal record only), address line, `barangay_id`, status, connection date. This never changes when tenants change.
- **`PortalUser`** is just the login identity — any email, not required to match `registered_name`.
- **`ConnectionLink`** (join table) connects a `PortalUser` to a `ServiceConnection`: `linked_at`, `unlinked_at` (nullable), `status` (active/revoked).
- **Linking is self-serve, no admin approval needed**: at signup, the portal user enters the `account_number` + `meter_number` from their physical bill — the same two identifiers the utility itself already uses to mean "this account," regardless of whose name is on it. This matches how payment already works informally (whoever holds the bill pays it, no ID check).
- When a renter moves out, their link is set to `revoked`; the next occupant links using the same account/meter numbers. The `ServiceConnection` record itself is untouched.
- Multiple simultaneous links are allowed (e.g. two boarders splitting one bill both want visibility) — the join table supports this naturally.

## Rate Schedule & Penalties

Based on real Sorsogon-area water bills reviewed:
- Some municipal water systems bill a **simple flat rate per cu.m.** (not tiered blocks) — e.g. ₱10/cu.m. flat. Design `RateSchedule` to support **either** a flat rate or tiered blocks (via a `RateTier` child table: `min_cu_m`, `max_cu_m`, `rate_per_cu_m`), so it works for a flat-rate MVP now and tiered billing later without a schema change.
- `RateSchedule` must have `effective_from` / `effective_to` dates — rates change over time, and historical invoices must keep reflecting the rate that applied when they were billed, not today's rate.
- **Rate assignment**: `ServiceConnection.rate_schedule_id` (nullable FK) — billing uses the connection's schedule when it's effective for the period, otherwise falls back to the single globally-active schedule. Seeded: one flat ₱10/cu.m. schedule ("Standard Flat Rate", 2026-01-01 → ∞) assigned to all connections. **Post-MVP: residential vs commercial rate classes** (real PH water districts bill them differently) — add a `class`/`rate_class` column on `ServiceConnection` + seed a commercial schedule; deferred until after Payments/Admin are done (see Deferred).
- **Penalty**: confirmed from a real bill — **2% per month interest** on any unpaid balance, disconnection follows after due date. Store this as data (`PenaltyRule`: `percent_per_month`, `grace_period_days`, `disconnection_after_days`), not hardcoded in billing logic, since municipalities can and do change these.
- **Arrears**: real bills carry forward unpaid balances month over month with accruing interest (see the "ARREARS" table on the sample bills — one row per month). `Invoice` must store `previous_balance` (carried arrears) separately from the current period's `base_amount`, so the itemized breakdown is transparent — customers will dispute bills, and this is what lets you show your work.

## Billing

- **Flagged readings must not feed billing math**: a flagged reading (meter replacement, `present < previous`) stores negative `cu_m_used`. Feeding it into billing produces negative consumption → negative/zero bill → breaks the system. **BillingService skips flagged readings (level 1 or 2) and reports them** (`billing:run` report: "Flagged reading (level N) — investigate, then bill manually"). These are rare (<10/year — a physical meter swap) and are handled by investigation + manual invoice entry, which for now happens offline (no admin billing UI yet — that's the Admin Panel phase; offline payment recording is a tracked Payments item). Billing math can never see a flagged reading.
- **No minimum charge** for connections without a reading in the period: **BillingService skips and reports them** ("No reading in the billing period"). Real PH bills carry a minimum monthly charge, but the schema has no minimum-charge field — do not add one until the utility confirms the value (see Deferred). First-month connections pay offline over the counter, then enter the online billing cycle.
- **Zero-usage readings are skipped and reported, not billed at ₱0.00**: a reading with `cu_m_used = 0` (vacant property, vacation) would create a 0.00 invoice that lingers as noise arrears and flips to "overdue". Skipped instead ("Zero usage — verify meter locked/closed, or bill manually") — the office can lock/close the physical meter for long-vacation accounts (offline workflow). If a minimum monthly charge is ever added (see Deferred), revisit this.
- **Invalid billing inputs never reach billing math — skip + report, run keeps going**: unflagged negative usage → "Non-positive usage (X cu.m.) — investigate"; a schedule that cannot compute a rate (flat with null/zero rate, tiered with no tiers) → "Rate schedule misconfigured". The run completes and names the accounts instead of silently billing 0.00/negative, and instead of aborting the whole run.
- **Invalid `--period` is rejected, not normalized**: only real calendar dates (`checkdate`) are accepted; `billing:run` exits 1 and `run()` throws `InvalidArgumentException` — `strtotime` never silently normalizes e.g. `2026-02-31` into a wrong month.
- **Penalty model**: at each billing run, unpaid invoices with `due_date` before the period end are marked `overdue`. A connection's new invoice carries `previous_balance` = sum of unpaid invoice totals, `penalty_amount` = accrued 2%-per-month interest on each (starts after due date + grace period, full 30-day buckets), and `base_amount` = current period usage × rate. Total = all three. Due date = period end + grace days.
- **Billing window**: one run per calendar month, `period_end` = last day of the month being billed (default) or explicit `--period=YYYY-MM-DD`; readings must fall within the exact calendar month of `period_end` (timestamps from month start 00:00:00 to period end + 1 day, exclusive). Latest reading in the window wins (the 30-day reading gap keeps this to at most one meaningful reading per cycle). Idempotent: a reading already covered by an invoice is skipped on re-runs — enforced by a DB unique constraint on `invoices (service_connection_id, meter_reading_id)` as well as the app-level check, so a concurrent run fails loudly instead of double-billing.
- **Rate fallback is visible**: when a connection's assigned schedule is not effective for the period and the global schedule is used instead, the billed report row notes "Global rate (assigned schedule not effective for this period)."
- Composite index on `meter_readings (service_connection_id, entered_at)` — added (billing queries readings per connection by date; the window query uses timestamp ranges, not `whereDate`, so the index is actually used).
- **Billing run is a queued job, not synchronous**: `php artisan billing:run` dispatches `App\Jobs\RunBillingJob` (database queue driver) by default; `--sync` runs inline for tests/manual verification. Every run — queued or sync — records a row in `billing_runs` (`period_end`, status `running`/`completed`/`failed`, JSON report of billed/skipped rows, `error`, `finished_at`): durable audit trail for a money-critical flow and the data source for the Admin Panel phase's "Run billing" page (`billing:report {id}` prints it from the CLI until then). A Postgres partial unique index blocks two concurrent `running` runs for the same month (command pre-check + DB backstop); re-runs of completed/failed periods stay possible (idempotent). The job retries 3× — a failed run persists nothing (`run()` is one transaction). Run the worker with `php artisan queue:work --tries=3` (dev) — the monthly scheduler wiring (run on the 1st) is deferred to the Infra phase, which needs a host running cron + a worker; until then monthly runs are a manual `billing:run`.
- Full decision catalog (what was decided, what still needs office confirmation): `docs/insights/billing-decisions.md`.

## Security

- `/admin` uses separate Filament auth guard — not reachable by API tokens
- Public API routes rate-limited; admin routes have separate limits
- Sanctum Bearer tokens for Next.js (not SPA cookie mode — simpler with separate domains)

## Invoices & Storage

- PDF generated via dompdf from the Invoice row + relations (read-only) — `App\Services\PdfService`
  renders the `pdfs.invoice` Blade view in-memory (no permanent storage); Payment phase reuses
  `PdfService::generate()` to email the attachment. `billing:pdf {invoice-number} [--output=]`
  writes the file for manual verification (default: storage disk `pdf-verification/<number>.pdf`).
- Itemized breakdown matches the real bill: Current Charges (base_amount from usage × rate),
  Arrears (previous_balance), Penalty (penalty_amount), Total. Letterhead "GUINOBATAN WATERWORKS"
  is a placeholder to be confirmed with the office (single edit point in the view).
- Emailed to customer as attachment — no permanent file storage
- If "download past invoices" feature needed later, regenerate PDF from DB data (bill amount, date, customer) — no files to host
- Real municipal water bills reviewed don't yet have a QR/online payment option (unlike the electric co-op bill, which does) — this is the actual gap GW-System fills. PayMongo checkout can generate its own QR/payment link per invoice; no need to replicate SOReco's QR format specifically.

## Payment Webhook Handling

- PayMongo webhook route must be **idempotent**: check if the invoice is already marked paid before processing, since webhook providers can retry/send duplicate notifications
- Verify webhook signature before trusting the payload

## Smart Features (post-MVP, not urgent)

- AI/statistics layer is **explanatory only** — it must never calculate or decide a bill amount; billing math stays 100% deterministic Laravel service code, auditable line by line
- Cheap wins that need no AI at all, just math on data already being stored: leak/anomaly detection (compare new reading to customer's own trailing average), simple consumption forecasting (moving average), collections risk scoring (payment history aggregation)
- If/when an LLM is added: a small hosted API call (e.g. a low-cost model) to turn already-computed structured data into plain-language bill explanations or admin natural-language queries — not a local model, given expected volume is low (dozens–low hundreds of bills per cycle, not scale that justifies self-hosting)
- Do not start this section until Core Data Models → Meter Readings → Billing → Payments are fully working

## Testing, Monitoring, Backups

- **Backups**: enable automatic daily DB backups on whatever host you use
- **Testing**: manual testing of money-critical flows (payment, invoice generation) before going live
- **Error tracking**: optional — Sentry free tier later if needed

## Development

```bash
# Start Laravel (API + admin)
cd backend
php artisan serve

# Start Next.js (customer UI)
cd frontend
npm run dev
```

- Laravel serves on `http://127.0.0.1:8000`
- Next.js serves on `http://localhost:3000`
- CORS is configured to allow Next.js to call Laravel APIs (Bearer token auth)

---

## Implementation Status

> Keep this checklist in sync with the actual codebase. Check an item only when it is genuinely working end-to-end (not stubbed). Update in the same commit that completes the item.

### Foundation
- [x] Laravel 13 backend scaffolded, Postgres connected (local Postgres 18, not Sail — Docker unavailable)
- [x] Next.js frontend scaffolded (marketing pages)
- [x] CORS configured between frontend and backend
- [x] `.env.example` files present for both apps (no real secrets committed)

### Auth
- [x] Sanctum installed, Bearer token issuance on login working
- [x] Token revocation / logout working
- [x] Filament admin auth guard set up separately from API guard (is_admin flag + filter)
- [x] Login failure handling per site: Filament admin differentiates wrong credentials vs valid-but-not-admin ("This account does not have access to the admin panel." — custom `App\Filament\Auth\Login`); customer `/api/login` returns one generic message (no email enumeration) + `throttle:10,1`; frontend shows a friendly error when the server is unreachable
- [x] API unauthenticated responses: `/api/login` route is named `login` so `auth:sanctum` failures on API routes never crash with `RouteNotFoundException`. With `Accept: application/json` the response is a clean `401 {"message":"Unauthenticated."}`; without it, Laravel redirects to the named route instead of 500ing. REST clients (Thunder Client) inject `Accept: */*` by default — duplicate `Accept` headers make the first one win and produce the 500 (see README → Testing the customer API)

### Core Data Models
- [x] `Barangay` model + migration (seed 15 real Guinobatan barangays)
- [x] `ServiceConnection` model + migration (account_number, meter_number, registered_name, barangay_id, status)
- [x] `PortalUser` model + migration (or extend default auth user — login identity only, not tied to registered_name) — used existing `User` model, added `phone` column
- [x] `ConnectionLink` model + migration (user_id, service_connection_id, status, linked_at, unlinked_at) + self-serve link-by-account-and-meter-number flow
- [x] `MeterReading` model + migration (present_reading, previous_reading, cu_m_used, entered_by, entered_at, method, flagged)
- [x] `RateSchedule` + `RateTier` models + migration (supports flat-rate and tiered, effective_from/effective_to)
- [x] `PenaltyRule` model + migration (percent_per_month, grace_period_days, disconnection_after_days — seed with 2%/month as confirmed real-world default)
- [x] `Invoice` model + migration (previous_balance/arrears, base_amount, penalty_amount, total_amount, due_date, status)
- [x] `Payment` model + migration (amount, method, paymongo_reference, paid_at, linked invoice(s))

### Meter Readings
- [x] Manual entry form in Filament (auto-computes cu_m_used, auto-fills previous_reading, auto-flags present < previous as level 2, minimum 30-day gap since last reading enforced, duplicate-date rejection)
- [x] CSV bulk import in Filament (upload → preview → validate → import)
- [x] Validation on import (per-row errors, flags suspicious readings, rejects invalid: bad rows, future dates, <30-day gaps since the previous reading, duplicates; optional `flagged` column respected; preview downloadable with notes for fix-and-reimport round-trip)

### Billing
- [x] Billing calculation logic in `App\Services\BillingService` (reads RateSchedule + PenaltyRule, never hardcodes rates; flat + tiered; arrears carryover + 2%/month penalty after grace; flagged readings and no-reading connections skipped + reported; `php artisan billing:run` for manual runs)
- [x] Billing run as a queued job (not synchronous) — `RunBillingJob` + `billing_runs` table (status + JSON report per run), `billing:run --sync` for inline runs, `billing:report {id}` to view a stored report, Postgres partial unique index blocking concurrent runs per period
- [x] Invoice PDF generation (dompdf) — itemized, matches real bill breakdown (current charges, arrears, penalty, total)

### Payments

> Detailed flow spec: `docs/prompts/payments-customer-portal-flow.md`. Sub-bullets are the
> small, individually-testable steps — one sub-bullet at a time per session.

- [x] PayMongo integration (create payment intent/checkout)
  - [x] Env vars: `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_LIVEMODE` + `config/services.php` entry (+ `.env.example`)
  - [x] Migration: add `paymongo_payment_intent_id` (nullable string, **unique index** — one intent per invoice) to `invoices`
  - [x] `App\Services\PayMongoService` — `createPaymentIntent()` POSTs `/v1/payment_intents` (Basic auth secret key, amount in centavos `round(total*100)`, returns intent id + `client_key`, persists on invoice, `Idempotency-Key: invoice-pay-{id}` guards double-submit; `payment_method_allowed` validated against PayMongo's whitelist). `getPaymentIntent($id, $invoice)` (GET) verifies the intent's `metadata.invoice_id` matches the invoice. `getOrCreatePaymentIntent($invoice)` runs check-then-act inside a `DB::transaction` + `lockForUpdate()` on the invoice row (rejects non-`unpaid`/`overdue` statuses with `InvoiceNotPayableException`), so concurrent pay calls can't create duplicate intents or pay a just-paid invoice. **Stale-intent self-heal (2026-08-05 hardening):** when a stored intent is re-fetched, it is checked by status — `succeeded` → throw `PaymentAlreadyCompletedException` → `/pay` returns 409 rather than letting the customer pay an already-paid transaction twice (the payment.paid webhook was missed; reconcile flags it); 4xx on GET (unknown/expired intent) → stored id replaced with a fresh intent (kills the "stored dead intent returned forever" E2E gotcha — no more manual DB nulling); ownership-mismatch metadata → fresh intent; `awaiting_*`/`processing` → returned as-is so the customer continues. 5xx/network → throw (stored id untouched). HTTP: 15s timeout, manual retry (up to 3 attempts) on 5xx + connection errors — safe because POST carries an Idempotency-Key; `paymongo` log channel.
  - [x] API endpoint: `POST /api/invoices/{invoice}/pay` (Sanctum, `throttle:20,1`) — rejects non-payable invoices (409: "already paid" or "not payable"), checks active link to the connection (403), returns `client_key` + payment intent id; PayMongo/network failure → 502 + `report()`
  - [x] Tests (faked HTTP): PayMongoService + endpoint feature test (incl. intent-ownership mismatch, non-payable statuses, method whitelist, 5xx retry, 429 rate limit)
- [x] PayMongo webhook route (signature verified, idempotent)
  - [x] `POST /api/paymongo/webhook` (no Sanctum — outside the auth group, no CSRF since the `api` group has none) — controller does zero DB/HTTP work, returns `200 {"received":true}` well within the 30s ack window
  - [x] Signature verify: `PayMongoService::verifyWebhookSignature()` — **timestamped** scheme per PayMongo's `developer-tools-webhook-setup-management` page (final format, verified 2026-08-04): the `Paymongo-Signature` header is `t=<unix timestamp>,te=<test sig>,li=<live sig>`; the signed string is `"<t>." . $rawBody` (`$request->getContent()`, before any parsing); digest is HMAC-SHA256 with `PAYMONGO_WEBHOOK_SECRET`, HEX-encoded; compare against `te` (test mode) or `li` (live mode) with `hash_equals` (timing-safe); fails closed (401) when the secret, signature, timestamp, or selected part is missing/empty. **Correction history (2026-08-04):** two earlier implementations (base64 digest of the body, then hex digest of the body alone) rejected every real delivery with 401 — both missed the `<t>.` prefix and the `te`/`li` selection, and unit tests were self-consistent (helper signed in the same wrong format); the `whsk_`-prefixed `.env` secret matched the dashboard value byte-for-byte and was correct all along. Header spelling is `Paymongo-Signature` with `X-Paymongo-Signature` accepted as fallback; which spelling actually arrives is logged to the `paymongo` channel (`storage/logs/paymongo.log`)
  - [x] Livemode guard: event `attributes.livemode` must match `PAYMONGO_LIVEMODE`, else ack + skip (never error); register **separate** webhook endpoints for test and live in the PayMongo dashboard. `config/services.php` reads the env var through `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — a literal `PAYMONGO_LIVEMODE=false` in `.env` parses to boolean `false`, not the truthy string that `(bool) "false"` would produce
  - [x] Anything that verifies is acknowledged (200), never 4xx/5xx — malformed JSON, missing livemode, and unknown event types all ack + skip (4xx/5xx would trigger PayMongo's up-to-12× retry logic). Known event types (`payment.paid`, `payment.failed`, `payment_intent.succeeded`, `payment_intent.awaiting_payment_method`) are logged + acked; dispatch to the queued job lands with the next item (invoice marked paid)
  - [x] Tests: 19 webhook tests (missing/invalid signature → 401, secret unconfigured → 401, unknown type / livemode mismatch / malformed JSON → 200 skip, known event → 200 ack, GET → 405, `X-Paymongo-Signature` fallback, raw-byte verification with UTF-8 body, plus format regression guards: body-only HMAC → 401, base64 digest → 401, mismatched header timestamp → 401, missing `t`/`te` parts → 401, whitespace tolerance, live-mode `li` selection)
- [x] Invoice marked paid on webhook confirmation
  - `App\Jobs\ProcessPayMongoWebhook` (ShouldQueue, tries=3, backoff [10,30,60]) — controller's known-event branch dispatches it (still acks `{received:true}` within 30s, never errors); job re-parses the payload defensively, never throws on malformed/missing data (logs + skips — retries would never resolve)
  - Dedupe two ways: `processed_webhook_events` table (unique index on `event_id`; the row is written **atomically with the state change** in one transaction — a failure rolls the dedupe row back too, so the job retry reprocesses cleanly instead of hitting the index and silently dropping a paid event; `UniqueConstraintViolationException` is caught at the outermost transaction level → full rollback + "already processed") **and** skip when the invoice is already `paid`; only ever transition `{unpaid, overdue} → paid` (overdue bills are payable via `/pay`, so overdue → paid is required). The dedupe insert itself is wrapped in its own `DB::transaction` so a duplicate insert on the non-`payment.paid` branch (which has no outer transaction) recovers the Postgres connection before its catch runs — this branch's duplicate handling (2026-08-05 hardening) logs "event already processed" + returns instead of letting the unique violation fail the job into `failed_jobs` on dashboard Resends / batch redeliveries after the app was briefly down
  - On `payment.paid` only (the **only** mark-paid trigger; `payment_intent.succeeded` is log-only — both fire on success, the already-paid guard dedupes them): job finds invoice by `paymongo_payment_intent_id`, then `PaymentService::markPaidFromWebhook(Invoice, paymentId, amountCentavos, paidAt)` (new `App\Services\PaymentService`, business logic kept out of the job/controller) — atomic `DB::transaction` + `lockForUpdate` on the invoice, **amount guard** (event centavos must equal `round(total*100)`, else log `paymongo` error + no state change),   creates `Payment` row (method `paymongo`, amount in pesos, `paymongo_reference` = **payment id** `pay_…` — the transaction-level ID, matches dashboard exports; corrected from the earlier "intent id" note, `paid_at` from event when present else `now()`), sets invoice `status = paid`; unique index on `payments.paymongo_reference` (DB backstop against duplicate payment rows; Postgres allows multiple NULLs, so non-PayMongo methods stay unconstrained)
  - On `payment.failed` / `payment_intent.succeeded` / expiry events: log only, no state change (still recorded in `processed_webhook_events` so redeliveries aren't reprocessed)
  - **`php artisan paymongo:reconcile` (read-only safety net, added 2026-08-05)** — finds charges that were never credited locally, because money-critical flows stay manual. Leg A: every `unpaid`/`overdue` invoice holding a stored intent is checked via the API — `succeeded` → "CHARGED BUT NOT CREDITED" (the webhook-missed case where `/pay` is now 409-blocked; admin credits the invoice manually or re-checks the dashboard). Leg B: `GET /v1/payments` (recent window, `status=paid`, paginated via `after`) cross-referenced against `payments.paymongo_reference` — an unmatched `pay_…` is "PAYMENT WITHOUT LOCAL RECORD" (also catches orphans from the "no invoice for intent" path). 5xx/network on either leg → "UNCHECKED", never a false finding. Console table + `paymongo` log channel; exit 1 when anything is reported; never mutates state, never auto-creates payments/credits. Runs daily on the host (scheduled with the Infra-phase cron alongside billing).
- [x] Invoice PDF emailed to customer on payment confirmation
  - `App\Mail\PaymentConfirmation` (Markdown mailable) + `App\Jobs\SendPaymentConfirmationEmail` (ShouldQueue, tries=3, backoff [10,30,60]) — dispatched from inside `PaymentService::markPaidFromWebhook` with `->afterCommit()`, and ONLY when a Payment row was actually created (already-paid / amount-mismatch → never emailed). Reuses `PdfService::generate(Invoice)` as an in-memory PDF attachment (`invoice-<number>.pdf`, no permanent storage). Template: invoice number, billing period, amount paid, invoice total, date paid. Subject: "Payment received — Invoice <number>"
  - Hardening (2026-08-05 audit): job guards against a missing service connection (log + skip, never crash), only emails links that are `active` **and** `unlinked_at IS NULL` (defense-in-depth on top of the revoke flow), and a `failed()` hook logs a loud `paymongo`-channel error (invoice, number, payment id) when retries are exhausted — a permanently-failed receipt is visible to ops in `failed_jobs`, never silent. Delivery is at-least-once by design (a retry after a partial send may duplicate the receipt; the payment itself is already committed before dispatch, so money is never at risk). Shared-To is a deliberate product decision — see product-decisions.md §17
  - **Failed-receipt alerts (2026-08-05, Phase 1):** `->databaseNotifications()` enabled on the panel; the job `failed()` hook now also sends a **Filament database notification to every `is_admin` user** (danger pill: invoice #, payment #, resend command) on top of the `paymongo` log line. A human hits the bell and runs **`php artisan paymongo:send-receipt {invoice}`** to resend the non-identical email to all linked users (skips silently when no recipients; exit 0 = sent/skipped, exit 1 = unknown invoice or unpaid). Notification hub UI (read/mark-all) is Phase 2 — see ### Notifications
  - **Recipients** (decided 2026-08-05): every distinct valid email of portal users with an `active` `ConnectionLink` to the invoice's connection — multiple boarders splitting one bill all get the confirmation (matches the renter/boarder design). Emails are lowercased + deduped (case-variant duplicates collapse; `users.email` unique index is case-sensitive so both rows can exist); revoked links excluded; a user with no valid email is skipped; no recipients at all → log `paymongo` channel + skip — the payment is already recorded by then, nothing is lost
  - Mailtrap in dev, Resend in prod: dev = `MAIL_MAILER=smtp` + Mailtrap SMTP creds from the free Testing inbox; prod = `MAIL_MAILER=resend` + `RESEND_API_KEY` (Laravel 13 ships the resend transport natively — no package). Both documented in `.env.example`; `MAIL_MAILER=log` remains the no-account fallback (writes to storage/logs). Mailtrap/Resend account setup is manual (user-side) — see README mail table
  - Test log isolation (2026-08-05, corrected): `phpunit.xml` points the paymongo channel at a throwaway path (`PAYMONGO_LOG_DRIVER=single` + `PAYMONGO_LOG_PATH=storage/logs/testing/paymongo.log`, gitignored via `*.log`) so `php artisan test` never touches the real `paymongo.log`. Note: Laravel 13 has no `array` log driver — the earlier `PAYMONGO_LOG_DRIVER=array` setting silently fell back to the emergency logger (writes went to `storage/logs/laravel.log` instead of being discarded); details in product-decisions.md §17
- [x] **Record offline/manual payments in admin** (cash / over-the-counter): mark invoice paid with method + reference — the real utility pays many bills offline (first-month connections, flagged-reading manual invoices); nothing records that today. Implemented as a create-only Filament `PaymentResource` (`/admin/payments`; index/create/view — no edit/delete on money rows) backed by `PaymentService::recordOfflinePayment`
  - Migration: added nullable `reference` (OR no.) + `recorded_by` (nullable FK users, nullOnDelete — audit trail mirrors `meter_readings.entered_by`) to `payments`. Offline rows keep `paymongo_reference` NULL — the unique index + `paymongo:reconcile` cross-check stay meaningful
  - `PaymentService::recordOfflinePayment($invoiceId, $amount, $reference?, $paidAt?, $recordedBy?, $method='cash')` — atomic `DB::transaction` + `lockForUpdate`, only `{unpaid, overdue} → paid`, rethrows on already-paid (concurrent webhook pay loses loudly — danger toast, nothing recorded). Methods are a free string from `PaymentService::OFFLINE_METHODS = ['cash']` (single extension point; add `check`/`bank_deposit` later = one line, no migration)
  - **Nearest-peso tolerance**: real PH payers don't split centavos, so the recorded amount just needs to be **within ₱1.00 of the invoice total** (form auto-fills `round(total)`); ≥ ₱1.00 off → rejected (real partial/overpayment). No partial-payment model (deferred — changes invoice status semantics); decision logged in product-decisions §20
  - **Hardening (2026-08-06 review, item-5 audit):** future-date guard runs on **day granularity** — `paid_at` compares `toDateString()` against today, so a same-day value with a time component is accepted (a payment date cannot be a *future day*, not a future timestamp); the value is parsed once in a try/catch so a garbage string becomes a clean `InvalidArgumentException` ("not a valid date"), never a Carbon error mid-transaction. `reference` is capped at 100 chars in the service (`mb_strlen`) and the form's `maxLength` matches the column — the CLI, form, and any future caller share one guard. Recording offline on an invoice that still holds a `paymongo_payment_intent_id` **logs a loud `paymongo`-channel warning** (invoice + intent id, double-collection watch) instead of blocking — an abandoned intent self-heals on `/pay`, so only `paymongo:reconcile` Leg B is the authoritative backstop for a real double collection (product-decisions §21)
  - **No receipt email on offline payments** — the counter customer holds the paper bill; portal users may not even have an email. `PaymentConfirmation` stays online-only (see product-decisions §20)
  - Create form: invoice searchable select (unpaid/overdue only, by invoice # / account / meter / name), auto-filled amount, method, optional reference/OR no., `paid_at` (default now, future dates rejected, backdating a batch allowed), `recorded_by` = admin. Paid invoices are now visible as paid wherever status is read (billing/penalty skip `paid`)
  - **CLI verification without a browser**: `php artisan payments:record {invoice-id-or-number} [amount]` — `--method=cash`, `--reference=`, `--paid-at=`, `--recorded-by=` options; amount defaults to the nearest peso; exit 0 = recorded, exit 1 = failure with the reason on stderr-style output (`error()`). Mirrors the `paymongo:send-receipt` command pattern — handy before the Admin Panel UI is fleshed out (an admin can also be tested via `--recorded-by`). The "payment date cannot be in the future" rule lives in `PaymentService` (not just the form), so the CLI path is guarded identically
- [x] **PayMongo channel captured + admin display fixed** (2026-08-06): badge/Recorded By/Reference were blank or generic for online payments
  - Migration: added nullable `paymongo_source` (string 30) to `payments` — the raw PayMongo channel key (`gcash`, `card`, `qrph`, …) from the webhook payload's `data.attributes.data.attributes.source.type` (verified against a real delivery: `external_reference_number` is null, so the source type is the only channel signal). Stored **channel only** — card brand/last4 deliberately NOT stored (user decision; display only needs the channel)
  - `ProcessPayMongoWebhook` extracts the source type defensively (`is_string` check, null-safe) → `PaymentService::markPaidFromWebhook(..., ?string $paymongoSource = null)` trailing param → column. Missing source in a payload → null, never a crash
  - **Reference stays NULL for PayMongo rows** — `reference` is the OR/receipt number (office semantics); display falls back `reference ?? paymongo_reference ?? '—'` in the table column, view form (`formatStateUsing`), and the confirmation email. No backfill, no mirroring (decision: product-decisions §23)
  - **`recorded_by` stays NULL for PayMongo rows** — the DB audit column means "which admin took the cash"; the view shows a display-only "Recorded By → PayMongo" placeholder. Same for the table column (`recordedByLabel`)
  - View page Payment Method select now widens `options()` to include the record's actual method (labeled `PayMongo · GCash` via `methodLabel`), because `getOptionLabelUsing` does NOT render for a native (non-searchable) select — the value not in `options` rendered blank. Table badge shows the same `PayMongo · <channel>` label; the `method` filter gains a `paymongo` option
  - Tests: webhook stores `paymongo_source` for card + gcash, null when the source is missing; offline flow untouched; view page renders method label + reference fallback + recorded-by fallback (Livewire `assertFormSet` + `assertSee`); email shows the fallback reference

### Customer Portal (Next.js) — buildable later

> Blocks: the Payments backend items (above) must work first — the UI consumes their API
> endpoints. Portal shell (dashboard + unpaid-bills list) must exist before any payment
> screen. Detailed spec: `docs/prompts/payments-customer-portal-flow.md` (frontstage spec).

- [ ] Portal shell: dashboard + unpaid-bills list (blocks all payment UI)
- [ ] Payment flow Screen 1 — Payment Method (three tappable cards: E-wallet, Card, Digital Wallet)
  - QR Ph (E-wallet, recommended/default): render in-page from the intent's `next_action.code.image_url` (Base64); countdown driven by the backend deadline (`expiry_seconds: 600`), never a hardcoded timer
  - GCash (E-wallet, second option): redirect to `next_action.redirect.url`; PayMongo's own page handles the `gcash://` deep link on mobile; 4-hr window, no countdown
  - E-wallet cap ₱1.00–₱100,000.00 — large commercial bills must go Card
- [ ] Payment flow Screen 2 — Review & Pay (line item, total, selected method + Change link, Pay button; pending state until the webhook confirms — never mark paid on redirect)
- [ ] Success / pending / expiry states + receipt line ("confirmation emailed to ...")
- [ ] Card form — collect details client-side, create Payment Method via PayMongo `/v1/payment_methods` with the **public key** (never through the Laravel backend; PCI note in the prompt file); 3DS redirect handling, re-fetch the intent server-side on return
- [ ] Tooltips: hover ⓘ on desktop, tap-to-toggle popover on touch — one consistent pattern, seed the `frontend-design` doc
- [ ] Save-card checkbox + vaulting — deferred, added in the same release as the card form, never before
- [ ] Digital Wallet (Google Pay) — showcase only; needs the account capability + Google Pay Console verification; mark "Coming soon" in the UI until verified

### Admin Panel (Filament)
- [x] Dashboard with key metrics (customers, unpaid invoices, revenue) — 2026-08-06
  - `App\Services\DashboardMetricsService` holds all aggregate queries (business logic stays in Services, not widgets): `activeConnectionsCount()`, `unpaidInvoicesCount()`, `overdueInvoicesCount()`, `receivablesOutstanding()` (`sum(total_amount)` on unpaid+overdue), `revenueThisMonth()`, `revenueLastMonths(6)` (zero-filled per-month series keyed `Y-m`). Returns raw `(float)`/`int` values — Postgres `numeric` aggregates return strings, so the `(float)` cast happens in the service (query builder doesn't apply model casts). **Future-date guard (audit 2026-08-06):** both revenue queries upper-bound `paid_at` at `now()->endOfMonth()` so a typo'd/backdated future-month `paid_at` can never inflate a month's revenue; same-month rows slightly ahead of the clock still count, keeping the stat and chart on the same month-boundary rule. `revenueLastMonths($months)` clamps to ≥ 1
  - `App\Filament\Widgets\MetricsOverview` (`StatsOverviewWidget`, v5) — 5 stat cards: active customers, unpaid bills, overdue bills (danger, "2% penalty per month"), outstanding amount (danger), revenue this month (success); money formatted `number_format()` + `₱` in the widget only
  - `App\Filament\Widgets\RevenueChart` (`LineChartWidget`, v5) — last-6-months revenue by `Payment.paid_at`, labels `M Y yyyy`; monthly boundary uses `now()` with `Asia/Manila` (config timezone)
  - Demo `FilamentInfoWidget` removed from the panel; discovered widgets render on the stock Dashboard page
  - **Metric definitions** (log in product-decisions §22): "customers" = **active service connections** (portal users are login identities only); revenue = **all `Payment.amount`** (PayMongo + offline cash) by `paid_at`, never `created_at`; outstanding = unpaid + overdue invoice totals
  - Tests (10 new, suite 220 → 230): `DashboardMetricsServiceTest` (counts/sums, empty DB → zeros, status split, month boundary) + `DashboardWidgetsTest` (Livewire widget render incl. ₱ values + zero-state; dashboard HTTP 200 with both widget components)
  - **Test-learning (2026-08-06):** `Payment::factory()` / `MeterReading::factory()` create their own related models, so a dashboard fixture must pin explicit `invoice_id`s — otherwise orphan random invoices silently pollute `sum()`/count metrics and the widget test asserts the wrong number with no failure in the factory itself

### Notifications
- [x] Email sending working (Mailtrap in dev, Resend in prod)
- [x] Failed receipt visibility: admin DB notification on confirmation-email failure (+ error logged to `paymongo`) — 2026-08-05
- [x] `php artisan paymongo:send-receipt {invoice}` resends a receipt to all linked users — 2026-08-05
- [ ] Notification hub UI (read/mark-all, history list) — Phase 2, after Admin Panel dashboard
- [ ] SMS notifications wired up (Semaphore/Twilio) — optional, later

### Infra / Ops
- [x] Graphify graph rebuilt vendor-free (`.graphifyignore` added: `backend/vendor/`, `backend/public/js/`; graph pruned 72,935 → 2,135 nodes, 2026-08-01)
- [ ] Queue worker running (database driver)
- [ ] Automatic daily DB backups enabled on host
- [ ] Basic rate limiting on public API routes

### Smart Features (post-MVP)
- [ ] Leak/anomaly detection (trailing average comparison, no AI)
- [ ] Consumption forecasting (moving average, no AI)
- [ ] Collections risk scoring (payment history aggregation, no AI)
- [ ] LLM-powered bill explanation / admin natural-language query (hosted API, optional)

### Deferred (noted, not scheduled)
- **Meter replacement marker**: dedicated flag/note on a reading when a physical meter is swapped (present < previous is legitimate then), so a backward reading isn't mistaken for an error by another user. Deferred — the current `flagged` workflow suffices; the billing guard above (Billing section) is the functional requirement.
- **Residential vs commercial rate classes**: real PH water districts bill them at different rates. Add `rate_class` to `ServiceConnection` + a commercial `RateSchedule` + assign per class. Deferred until after Payments + Admin Panel phases (MVP = one flat rate for all, per-connection override already possible via `rate_schedule_id`).
- **Minimum monthly charge**: real PH bills carry a minimum charge (e.g. first X cu.m. billed at a fixed amount). Not in the schema and NOT implemented — billing skips connections without a reading instead. Add `minimum_charge` to `RateSchedule` only when the utility confirms the exact value.
- **Estimated billing for malfunctioning / abnormally high meters** (documented 2026-08-03, user-raised): settle a bad reading at the last correct bill, or the highest of the last 3 bills — office must confirm the actual rule (question A12 in `billing-decisions.md`). Deferred to the Admin Panel phase's manual-invoice entry UI (suggest estimate → admin confirms → invoice records its basis). NOT an automatic branch in `billing:run` — flagged readings stay investigate-then-bill-manually. "Unusually high but positive" readings are a separate case → leak/anomaly detection (Smart Features).
