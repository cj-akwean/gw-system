# GW-System — Showcase / Testing Runbook

> Presentation-ready demo of the Guinobatan Waterworks System: customer portal (frontend),
> JSON API + admin panel (backend), and the full PayMongo payment chain. Every phase lists
> the exact command and what you should see. Verified 2026-08-10. Updated 2026-08-10 — ngrok/webhook setup added.

## What the demo proves

| # | Phase | Proves |
|---|---|---|
| 1 | Backend API | Auth, rates, meter linking, invoice listing, payment-intent creation |
| 2 | Frontend portal | Landing page, self-registration, onboarding, dashboard, pay flow |
| 3 | Payments | Real QR Ph scan (internet) **or** offline webhook simulation (`--source=qrph`) |
| 4 | Webhook integrity | Invoice marked paid, Payment row, dedupe, reconcile |
| 5 | Receipt email | Confirmation email with PDF attachment (Mailtrap) |
| 6 | Admin (Filament) | Dashboard metrics, CRM, billing run, CSV exports |
| 7 | Evidence | Automated test suites (backend + frontend) |

## Evidence (run 2026-08-10)

| Suite | Result | Command |
|---|---|---|
| Backend (PHPUnit) | **534 passed** · 2,347 assertions · ~95s | `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit` (from `backend/`) |
| Frontend (Vitest) | **175 passed** · 18 files · ~38s | `npm test` (from `frontend/`) |
| Build | Static export, TS clean, 7 routes | `npm run build` |
| Lint | 4 errors / 8 warnings — pre-existing baseline, none new | `npm run lint` |

## Prerequisites (all four, or the demo silently fails)

1. **Postgres running** (service `PostgreSQL`, db `gw_system`) — `Start-Service PostgreSQL`
2. **PayMongo test keys** in `backend/.env` (`PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY` = `pk_test_…` / `sk_test_…`)
3. **Windows SSL fix** in place — outbound HTTPS fails with `cURL error 60` otherwise; verify: `php -r "echo ini_get('curl.cainfo');"` prints a `.pem` path (see root `README.md → PHP HTTPS / SSL`)
4. **Mailtrap** SMTP creds in `backend/.env` for Phase 5 (optional — without them email is written to `storage/logs` instead, `MAIL_MAILER=log`)
5. **ngrok** running — required for real PayMongo webhooks to reach your machine (Phase 3A). Install: `winget install ngrok`. Start: `ngrok http 8000`. Copy the `https://...` URL and paste it into **PayMongo Dashboard → Developers → Webhooks → endpoint URL**. **Every time ngrok restarts, the URL changes — update the dashboard or webhooks silently fail.**

## Pre-flight (3 terminals)

```powershell
# Terminal 1 — Backend (API + admin)
cd backend
php artisan serve

# Terminal 2 — Frontend (customer portal)
cd frontend
npm run dev

# Terminal 3 — Queue worker (webhooks, emails, PDFs, billing runs)
cd backend
php artisan queue:work --tries=3
```

Reset the billing data so every demo run starts from a known state (75 invoices,
15 connections × 5 months; 38 paid / 20 overdue / 17 unpaid):

```powershell
cd backend
php artisan test:seed-data --fresh     # full reset (migrate:fresh + reseed)
# or, softer: php artisan test:seed-data   # wipes payments/invoices/readings/links only
```

Sanity check the gateway reachable (401 = good):

```powershell
curl.exe -s -o NUL -w "%{http_code}" https://api.paymongo.com/v1/payment_intents
```

---

## Phase 1 — Backend API

```powershell
# 1. Login → Bearer token
$body = @{ email = 'test@example.com'; password = 'password' } | ConvertTo-Json
$login = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/login' -Method Post -ContentType 'application/json' -Body $body
$login | ConvertTo-Json                      # → { token: "1|…", user: {…} }
$headers = @{ Authorization = "Bearer $($login.token)" }

# 2. Public rates (no auth needed)
Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/rates' | ConvertTo-Json   # → flat ₱10.00/m³ + penalty rule

# 3. Link meter (self-serve, from the physical bill)
$link = @{ account_number = 'GW-00001'; meter_number = 'MTR-00001' } | ConvertTo-Json
Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/links' -Method Post -Headers $headers -ContentType 'application/json' -Body $link | ConvertTo-Json

# 4. List invoices
Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/invoices' -Headers $headers | ConvertTo-Json

# 5. Create a payment intent (use an UNPAID invoice id from step 4)
$pay = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/invoices/{ID}/pay' -Method Post -Headers $headers -ContentType 'application/json'
$pay | ConvertTo-Json                       # → { client_key, payment_intent_id, expiry_seconds }
```

Edge cases worth showing: no token → `401 {"message":"Unauthenticated."}` · paying an
already-paid invoice → `409` · invoice of an unlinked connection → `403`.

## Phase 2 — Frontend portal (browser)

1. **Landing** — `http://localhost:3000` — animated hero, Rates card (live ₱10.00/m³ + 2% penalty / 15-day grace), How It Works, Contact
2. **Register** — Sign In → "Create account" → any email + password → auto-login
3. **Onboarding** — pick avatar + username → **link meter** (`GW-00001` / `MTR-00001` — or skip, `I'll do this later`)
4. **Dashboard** — `http://localhost:3000/dashboard` — unpaid bill cards (current charges / arrears / penalty), per-connection dividers, **Past payments** drawer, mini-calendar
5. **Pay flow** — Pay on an unpaid bill → `http://localhost:3000/dashboard/pay?id={id}` → method screen (**E-wallet · Card**) → review step (itemized line items + total) → Pay

## Phase 3 — Payments

### 3A. Real QR Ph (needs internet + test-mode PayMongo)

**Before testing, set up ngrok for webhooks:**

```powershell
# 1. Start ngrok (in a separate terminal or background)
ngrok http 8000

# 2. Copy the https://... URL from the ngrok output

# 3. Go to PayMongo Dashboard → Developers → Webhooks → Edit your endpoint
#    Paste the ngrok URL + /api/webhooks/paymongo as the endpoint URL
#    Example: https://abc123.ngrok-free.app/api/webhooks/paymongo
```

> **Critical:** ngrok generates a new URL every restart. If webhooks silently fail,
> check that the dashboard URL matches your current ngrok session.

1. In the portal pay flow: E-wallet → **Scan QR · QR Ph** → Pay
2. PayMongo returns a QR image (Base64, rendered in-page) + expiry countdown
3. Scan with the **GCash app** (test mode) → approve → app redirects back (`return_url`)
4. Portal polls every 15s → **"Payment received"** panel with receipt line
5. PayMongo webhook fires → invoice marked paid (requires ngrok running + correct URL in dashboard)

> Bills over ₱100,000 are E-wallet-disabled (Card only); E-wallet minimum ₱1.00.

### 3B. Card (real checkout, no ngrok)

```powershell
# From Phase 1 step 5, grab client_key + payment_intent_id, then:
# open http://127.0.0.1:8000/pay-checkout.html
# paste PUBLIC_KEY (pk_test_… from .env), CLIENT_KEY, INTENT_ID → Pay
# any future expiry, any 3-digit CVC
```

**Test cards** (official PayMongo test-mode cards — `docs.paymongo.com/docs/payment-acceptance-testing`):

| Card number | Network | 3DS / OTP | What happens |
|---|---|---|---|
| `4343 4343 4343 4345` | Visa | **No 3DS** | Instant success — the default demo card |
| `4571 7360 0000 0075` | Visa | **No 3DS** | Instant success |
| `5123 0000 0000 0002` | Mastercard | **No 3DS** | Instant success |
| `4120 0000 0000 0007` | Visa | **Triggers 3DS** | Bank-challenge prompt appears — select **Authorize** (simulates the bank's SMS/OTP verification step; your app doesn't send real SMS yet, so the portal just shows the pending state until the prompt is approved) |
| `5123 0000 0000 0001` | Mastercard | **3DS supported but optional** | Choose Authorize or skip |

> Decline cards if you want the failure path: `4200 0000 0000 0018` (expired) ·
> `4300 0000 0000 0017` (invalid CVC) · `5100 0000 0000 0198` (insufficient funds) ·
> `4111 1111 1111 1111` (generic decline).

`INTENT STATUS: succeeded` → webhook fires → invoice marked paid.

### 3C. Offline fallback — webhook simulation (no internet, no ngrok, no test card)

Fires the **exact** `payment.paid` payload through the same `ProcessPayMongoWebhook`
job a real delivery would dispatch. **This is the QR Ph one**:

```powershell
cd backend
php artisan paymongo:simulate-payment           # first unpaid/overdue invoice
php artisan paymongo:simulate-payment 4         # by invoice id
php artisan paymongo:simulate-payment GW-2026-00004      # by invoice number
php artisan paymongo:simulate-payment 4 --source=qrph    # QR Ph channel
php artisan paymongo:simulate-payment 4 --source=gcash
php artisan paymongo:simulate-payment 4 --source=gcash --payer-name="Jane Doe" --payer-email=jane@example.com
```

Re-running on the same invoice → `is not payable (status: paid)` — expected.

## Phase 4 — Verify the payment chain

```powershell
Get-Content backend\storage\logs\paymongo.log -Tail 20
```

Expect: `webhook received` → `acknowledged: known event type` → `PayMongo webhook processed:
invoice marked paid {invoice_id, payment_id}` → `Payment confirmation email sent {recipients}`.

```powershell
php artisan tinker --execute="print_r(App\Models\Invoice::where('id',4)->first(['id','status'])->toArray()); print_r(App\Models\Payment::all(['id','invoice_id','amount','method','paymongo_reference','paid_at'])->toArray()); print_r(App\Models\ProcessedWebhookEvent::all(['event_id','event_type','processed_at'])->toArray());"
```

PASS = invoice `paid` · one `Payment` row (`method: paymongo`) · one `processed_webhook_events`
row. Run the simulation twice → second run skipped (`event already processed`) — **idempotency**.

```powershell
php artisan paymongo:reconcile     # read-only discrepancy check → expect OK / no findings
```

## Phase 5 — Receipt email (Mailtrap)

1. `mailtrap.io` → Email Testing → **the inbox whose SMTP creds are in `.env`** (creds are per-inbox)
2. Subject: `Payment received — Invoice GW-2026-00004` — body table (invoice number, billing
   period, amount paid, total, date paid) + **Attachments: `invoice-GW-2026-00004.pdf`**
   (download → verify itemized breakdown: current charges / arrears / penalty / total)
3. No email? → the portal user must have an **active link** to the paid connection
   (seeded `test@example.com` ↔ `GW-00001`). Simulate a failed receipt:
   set a bad `MAIL_HOST` → restart `queue:work` → simulate payment → admin bell →
   **Resend receipt** → toast → email lands.

## Phase 6 — Admin panel (Filament)

`http://127.0.0.1:8000/admin` → `admin@gwsystem.com` / `admin123`

1. **Dashboard** — customers (active connections), unpaid invoices, revenue chart
2. **CRM** — Service Connections: list, detail, edit; applicant fields; identifier edits
3. **Billing** — "Run billing" page: run for a past period (queued), watch progress poll,
   `billing:report {id}`; re-run → "Already billed" (idempotent)
4. **Exports** — Payments CSV / Service connections CSV / Invoices CSV (filter-aware)
5. **Meter readings** — CSV import (upload → preview → validate → import) + manual entry
6. **Notifications** — bell + Notification Hub (`/admin/notifications`)

```powershell
# Optional CLI twins for the same jobs
php artisan billing:run --period=2026-07          # needs the queue worker
php artisan billing:report {id}                   # print the run's report
php artisan paymongo:send-receipt {invoice}       # resend a receipt to linked users
```

## Phase 7 — Evidence (run in front of the room)

```powershell
# Backend (from backend/) — full suite, ~95s
php -d memory_limit=512M vendor/phpunit/phpunit/phpunit

# Frontend (from frontend/)
npm test          # 175 tests
npm run lint
npm run build     # static export, TS clean
```

---

## Command reference

| Purpose | Command |
|---|---|
| Start backend | `cd backend; php artisan serve` |
| Start frontend | `cd frontend; npm run dev` |
| Queue worker | `cd backend; php artisan queue:work --tries=3` (restart after `.env` change: `php artisan queue:restart`) |
| Reset demo data (full) | `php artisan test:seed-data --fresh` |
| Reset demo data (soft) | `php artisan test:seed-data` |
| Simulate payment — QR Ph | `php artisan paymongo:simulate-payment 4 --source=qrph` |
| Simulate payment — GCash | `php artisan paymongo:simulate-payment 4 --source=gcash` |
| Simulate payment — default | `php artisan paymongo:simulate-payment` (first unpaid/overdue invoice) |
| Payment reconciliation | `php artisan paymongo:reconcile` |
| Resend receipt | `php artisan paymongo:send-receipt {invoice}` |
| Billing run + report | `php artisan billing:run --period=2026-07` · `php artisan billing:report {id}` |
| Backend test suite | `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit` |
| Frontend test suite | `npm test` |
| Frontend lint / build | `npm run lint` · `npm run build` |
| Start Postgres | `Start-Service PostgreSQL` |
| PayMongo reachability | `curl.exe -s -o NUL -w "%{http_code}" https://api.paymongo.com/v1/payment_intents` |
| Inspect DB rows after pay | tinker one-liner in Phase 4 |

## URLs & credentials

| Item | Value |
|---|---|
| Laravel API | `http://127.0.0.1:8000` |
| Filament Admin | `http://127.0.0.1:8000/admin` — `admin@gwsystem.com` / `admin123` |
| Next.js portal | `http://localhost:3000` — `test@example.com` / `password` |
| Card test page | `http://127.0.0.1:8000/pay-checkout.html` — test cards in Phase 3B (no-3DS `4343 4343 4343 4345` / 3DS `4120 0000 0000 0007`) |
| Seeded connection | `GW-00001` / `MTR-00001` (linked to `test@example.com`) |
| Postgres | `postgres` / `postgres`, db `gw_system` |

## Production readiness (where the project stands)

- **Feature completeness: ~90%.** All core flows ship and pass: auth, billing, payments
  (QR Ph / GCash / Card), receipts, admin, exports. **Deferred:** Digital Wallet (Google Pay —
  reverted to "Coming soon" due to PayMongo sandbox testing limitations; backend wiring
  remains), SMS, Smart Features
  (leak detection, forecasting, risk scoring), residential/commercial rate classes,
  minimum-charge rule (needs the office's confirmed value).
- **Production readiness: ~75–80%.** The code is ready; the *Infra phase* is not executed:
  host + domain + TLS, live PayMongo keys, Resend sending domain, supervisor worker,
  host cron + backups. Everything is scripted and rehearsed in `docs/deployment-runbook.md`
  (incl. backup restore drill) — the remaining work is provisioning + one live smoke test.
- **Office confirmations needed before go-live:** rate values, penalty/grace numbers,
  invoice letterhead, minimum charge.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `cURL error 60` / payment 502 | CA bundle missing — root `README.md → PHP HTTPS / SSL`; check `storage/logs/laravel.log` |
| Invoice never marked paid | Queue worker not running — `jobs` table is filling; start `queue:work`. Or ngrok not running / webhook URL stale — restart ngrok and update PayMongo dashboard |
| No email in Mailtrap | Wrong inbox creds (per-inbox) or no active link on the paid connection; restart worker after `.env` change |
| Webhook deliveries stuck "processing" | **Most common:** ngrok URL changed after restart — update PayMongo dashboard → Developers → Webhooks. Also: queue worker down, or ngrok not running |
| `/pay` returns 409 "already went through" | Intent previously succeeded — double-charge guard working; run `paymongo:reconcile` |

> Deep dive: `docs/manual-tests/paymongo-payment-e2e.md` — the full manual test including
> the ngrok + real-webhook route and email verification addendum.
