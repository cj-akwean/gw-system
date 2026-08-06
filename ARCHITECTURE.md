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

- Billing math lives in `App\Services\BillingService` (never in admin UI/widgets). Each run bills one calendar month for **active** connections: usage × rate (flat or tiered — connection's assigned schedule when effective for the period, else the global active one) + carried arrears (`previous_balance`) + penalty (`2%/month` after due date + grace, full 30-day buckets, per `PenaltyRule` — data, never hardcoded); due date = period end + grace days.
- Anything billing can't trust is **skipped + reported, never billed**: flagged readings (meter swap / `present < previous`), no reading in the period, zero usage, invalid inputs (negative usage, misconfigured schedule), and invalid `--period` (rejected, not normalized). No minimum charge until the office confirms a value.
- Idempotent + race-safe: already-billed readings are skipped on re-runs; a DB unique constraint on `invoices (service_connection_id, meter_reading_id)` + a Postgres partial unique index block double-billing and concurrent runs for the same month.
- Run = queued job (`RunBillingJob`, database driver; `--sync` runs inline). Every run records a `billing_runs` row (status + JSON report); `billing:report {id}` prints it — the data source for the Admin Panel phase's future "Run billing" page. Monthly scheduler (cron + worker) is the Infra phase; until then `billing:run` is manual.
- Full decision catalog (decided + still-needs-office-confirmation): `docs/insights/billing-decisions.md`.

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
- [x] Billing calculation logic in `App\Services\BillingService` (reads RateSchedule + PenaltyRule, never hardcodes rates; flat + tiered; arrears carryover + 2%/month penalty after grace; flagged/no-reading/zero/invalid connections skipped + reported; `billing:run` manual runs) — full rules: `docs/insights/billing-decisions.md`
- [x] Billing run as a queued job (not synchronous) — `RunBillingJob` + per-run report row in `billing_runs` (`billing:run --sync` inline, `billing:report {id}`); Postgres partial unique index blocks concurrent runs per period
- [x] Invoice PDF generation (dompdf) — itemized, matches real bill breakdown (current charges, arrears, penalty, total)

### Payments

> Detailed flow spec: `docs/prompts/payments-customer-portal-flow.md`. Pre-trim sub-bullet detail: `docs/insights/checklist-archive.md`.

- [x] **PayMongo integration (create payment intent/checkout)** — `App\Services\PayMongoService::createPaymentIntent()` POSTs `/v1/payment_intents` (Basic auth, amount in centavos `round(total*100)`, persists `paymongo_payment_intent_id` on the invoice (unique — one intent per invoice), `Idempotency-Key: invoice-pay-{id}`, `payment_method_allowed` whitelisted, 15s HTTP timeout, 3× retry). `getOrCreatePaymentIntent()` check-then-act in `DB::transaction` + `lockForUpdate` (rejects non-`unpaid`/`overdue` with 409; self-heals stale intents — succeeded → 409 double-pay guard, expired/unknown → fresh intent); `paymongo` log channel. Endpoint `POST /api/invoices/{invoice}/pay` (Sanctum, `throttle:20,1`) — inactive link → 403, returns `client_key` + intent id, PayMongo failure → 502 + `report()`. Faked-HTTP tests. Env: `PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_LIVEMODE`
- [x] **PayMongo webhook route (signature verified, idempotent)** — `POST /api/paymongo/webhook` (no auth; controller only acks `200 {"received":true}` within the 30s window, zero DB/HTTP work). Signature: `Paymongo-Signature` header `t=<unix ts>,te=<test sig>,li=<live sig>`; signed string `"<t>." . $rawBody` (`getContent()`, pre-parse); HMAC-SHA256 hex; `hash_equals` vs `te`/`li` per `PAYMONGO_LIVEMODE`; fails closed 401 (exact format doc-verified — see AGENTS.md doc trap; `X-Paymongo-Signature` fallback accepted). Livemode guard (event must match `PAYMONGO_LIVEMODE`, read via `filter_var FILTER_VALIDATE_BOOLEAN`); separate test/live endpoints. Anything non-verifiable or unknown → ack + skip (never 4xx/5xx, avoids PayMongo's up-to-12× retry).
- [x] **Invoice marked paid on webhook confirmation** — `App\Jobs\ProcessPayMongoWebhook` (queued, tries=3) → `PaymentService::markPaidFromWebhook()` (business logic kept out of the job/controller): atomic `DB::transaction` + `lockForUpdate` on the invoice, **amount guard** (event centavos must equal `round(total*100)`), creates `Payment` row (`method=paymongo`, `paymongo_reference` = payment id `pay_…` — unique index backstop), sets invoice `status = paid`; only ever `{unpaid, overdue} → paid`; only `payment.paid` marks paid (other events log-only). Dedupe: `processed_webhook_events` (unique `event_id`, written atomically with the state change) + already-paid skip — dashboard Resends/redeliveries never double-pay. `paymongo:reconcile` — read-only CLI safety net (Leg A: charged-but-not-credited vs stored intents; Leg B: payments without local record; 5xx → "UNCHECKED"), runs daily on host.
- [x] **Invoice PDF emailed to customer on payment confirmation** — `App\Mail\PaymentConfirmation` + `App\Jobs\SendPaymentConfirmationEmail` (queued, dispatched `afterCommit` from `markPaidFromWebhook`, ONLY when a `Payment` row was created); in-memory PDF via `PdfService::generate()` (no permanent storage). Recipients = all distinct valid emails (lowercased + deduped) of portal users with an `active` + non-unlinked `ConnectionLink` (revoked excluded; none → skip, nothing lost — payment already recorded). Failure → admin Filament DB notification + `paymongo:send-receipt {invoice}` resend CLI (exit 0 = sent/skipped, 1 = unknown/unpaid). Mailtrap dev / Resend prod (`MAIL_MAILER=log` fallback); test log isolated via `PAYMONGO_LOG_DRIVER=single` + throwaway path in `phpunit.xml`. Delivery at-least-once by design; shared-To deliberate (product-decisions §17).
- [x] **Record offline/manual payments in admin** (cash / over-the-counter) — create-only Filament `PaymentResource` (`/admin/payments`; no edit/delete on money rows) backed by `PaymentService::recordOfflinePayment()`: atomic `DB::transaction` + `lockForUpdate`, only `{unpaid, overdue} → paid`, **nearest-peso tolerance** (≤ ₱1.00 of total), day-granularity future-date guard (garbage → clean `InvalidArgumentException`), `reference` ≤ 100 chars, `recorded_by` audit (nullable FK, mirrors `meter_readings.entered_by`), offline rows keep `paymongo_reference` NULL, warns (not blocks) when the invoice still holds an intent. `payments:record` CLI mirrors the form with identical guards. No receipt email on offline (product-decisions §20–21).
- [x] **PayMongo channel captured + admin display fixed** — `payments.paymongo_source` (string 30) = raw PayMongo channel key (`gcash`, `card`, `qrph`, …) from the webhook's `…source.type`; **channel only**, card brand/last4 not stored (PCI surface). `reference` and `recorded_by` stay NULL for online rows (OR/audit semantics) with display fallback `reference ?? paymongo_reference ?? '—'` / "Recorded By: PayMongo"; Method select widens `options()` with the record's actual method (native selects won't show values absent from `options`); no backfill (product-decisions §23).

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
- [x] Dashboard with key metrics (customers, unpaid invoices, revenue) — `App\Services\DashboardMetricsService` (aggregate queries; revenue upper-bounded at `now()->endOfMonth()`) + widgets `MetricsOverview` (5 stat cards) / `RevenueChart` (6 months by `Payment.paid_at`). Metrics: customers = **active service connections**; revenue = **all `Payment.amount`** (PayMongo + offline) by `paid_at`, never `created_at`; outstanding = unpaid + overdue totals (definitions: product-decisions §22)
- [ ] CRM views (customer/connection list, detail, edit) — `ServiceConnectionResource` (list + view + edit, no create/delete); also the dashboard stat-card deep-link targets. *[Restored 2026-08-06 — accidentally dropped in commit b45ee74 when item 1 was marked done.]*
- [ ] Billing management views — `InvoiceResource` (list by status, view detail/breakdown, mark paid) + "Run billing" page from `billing_runs` run reports (Phase 3 of `docs/prompts/billing-queue-pdf-admin.md`)

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
