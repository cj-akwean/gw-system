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
- Full decision catalog (what was decided, what still needs office confirmation): `docs/insights/billing-decisions.md`.

## Security

- `/admin` uses separate Filament auth guard — not reachable by API tokens
- Public API routes rate-limited; admin routes have separate limits
- Sanctum Bearer tokens for Next.js (not SPA cookie mode — simpler with separate domains)

## Invoices & Storage

- PDF generated on payment confirmation via dompdf
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
- [ ] Billing run as a queued job (not synchronous)
- [ ] Invoice PDF generation (dompdf) — itemized, matches real bill breakdown (current charges, arrears, penalty, total)

### Payments
- [ ] PayMongo integration (create payment intent/checkout)
- [ ] PayMongo webhook route (signature verified, idempotent)
- [ ] Invoice marked paid on webhook confirmation
- [ ] Invoice PDF emailed to customer on payment confirmation
- [ ] **Record offline/manual payments in admin** (cash / over-the-counter): mark invoice paid with method + reference — the real utility pays many bills offline (first-month connections, flagged-reading manual invoices); nothing records that today. Needs an admin view (see Admin Panel phase) + a `Payment` row with `method='cash'` etc.

### Admin Panel (Filament)
- [ ] Dashboard with key metrics (customers, unpaid invoices, revenue)
- [ ] CRM views (customer/connection list, detail, edit)
- [ ] Billing management views

### Notifications
- [ ] Email sending working (Mailtrap in dev, Resend in prod)
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
