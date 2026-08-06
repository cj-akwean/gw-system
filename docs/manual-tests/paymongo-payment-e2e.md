# Manual Test — PayMongo payment E2E (webhook → invoice marked paid)

> Written 2026-08-05 after the first successful end-to-end round (test mode).
> Purpose: verify the whole chain — `/pay` intent creation → customer completes payment →
> `payment.paid` webhook → `ProcessPayMongoWebhook` job → invoice `paid` + `Payment` row →
> dedupe on redelivery. Run this after any change to the payment/queue/webhook code.

## Quick alternative — local webhook simulation (no ngrok, no dashboard, no test card)

> Added 2026-08-06. `paymongo:simulate-payment` fires the exact `payment.paid` payload
> through the SAME `ProcessPayMongoWebhook` job a real delivery would dispatch — the
> invoice is marked paid, the Payment row recorded, the confirmation email queued.

```powershell
cd C:\Users\akwean\Downloads\gw-system\backend
php artisan paymongo:simulate-payment          # first unpaid/overdue invoice
php artisan paymongo:simulate-payment 3        # by id
php artisan paymongo:simulate-payment GW-2026-00004   # by invoice number
php artisan paymongo:simulate-payment 4 --source=gcash --payer-name="Jane Doe" --payer-email=jane@example.com
```

- Needs the queue worker running (`php artisan queue:work --tries=3`) for the email job.
- **The exact recipe for the failed-receipt test**: set a bad `MAIL_HOST` in `.env`,
  restart `queue:work`, run `paymongo:simulate-payment`, watch the admin bell →
  "Resend receipt" → click → toast → Mailtrap.
- The payer defaults to the first linked portal user (so the receipt shows a real
  recipient); no linked users → `Test Payer <test@example.com>`.
- Re-running on the same invoice → "is not payable (status: paid)" — expected; make a
  fresh invoice or pick another.
- After a successful **Resend receipt** click, the bell entry flips to "Payment confirmation
  email resent" (success color, button removed); clicking the button/URL again just shows a
  warning toast — no duplicate email (idempotent, `throttle:10,1` backstop).
- **What it does NOT cover** (deliberately): PayMongo signature verification, the real
  checkout (client_key attach / 3DS), and dashboard delivery. Use the ngrok recipe below
  for those.
- An invoice with no stored intent gets a fabricated `pi_sim_…` id; a leftover
  `pi_sim_…` id on an unpaid invoice shows as `UNCHECKED` in `paymongo:reconcile`
  (harmless — clear it with the tinker one-liner below if it ever bothers you).

## Prereqs (all four, or the test silently fails)

1. `php artisan migrate` — **the `processed_webhook_events` table must exist** (this was missed
   once and the job would have crashed on the dedupe insert).
2. `php artisan serve` (API on `http://127.0.0.1:8000`).
3. `php artisan queue:work` — queue driver is `database`; **without a worker the job sits in the
   `jobs` table and the invoice never gets marked paid**.
4. `ngrok http 8000` and the PayMongo dashboard webhook URL updated to
   `https://<tunnel>.ngrok-free.app/api/paymongo/webhook` (secret unchanged, matches `.env`).

## Data prep (dev DB)

- Customer: `test@example.com` / `password` (seeded, factory default). Link user → connection
  `GW-00001` / `MTR-00001` is seeded active.
- Pick an unpaid invoice for that connection. **If the invoice was used in a previous payment
  attempt that never completed, `/pay` now self-heals: a stored intent that PayMongo no longer
  knows (4xx) is replaced with a fresh one automatically.** If the previous attempt actually
  *succeeded* (the intent's status is `succeeded` but the invoice is somehow still unpaid), `/pay`
  returns 409 "already went through" — that's the double-charge guard; use
  `paymongo:reconcile` to confirm the finding, then pick a different invoice or clear the id by
  hand:

```powershell
cd C:\Users\akwean\Downloads\gw-system\backend
php artisan tinker --execute="App\Models\Invoice::where('id',1)->update(['paymongo_payment_intent_id'=>null]);"
```

## Create the intent

```powershell
$body = @{ email = 'test@example.com'; password = 'password' } | ConvertTo-Json
$login = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/login' -Method Post -ContentType 'application/json' -Body $body
$token = $login.token
$headers = @{ Authorization = "Bearer $token" }
$pay = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/invoices/1/pay' -Method Post -Headers $headers -ContentType 'application/json'
$pay | ConvertTo-Json   # -> { client_key, payment_intent_id } — must be a NEW pi_… id
```

## Complete the payment

`backend/public/pay-checkout.html` (reusable tool, placeholder keys) →
open `http://127.0.0.1:8000/pay-checkout.html` → paste `PUBLIC_KEY` (`pk_test_…` from `.env`),
`CLIENT_KEY`, `INTENT_ID` → Pay → test card **4343 4343 4343 4345**, any future expiry, any CVC.

- `INTENT STATUS: succeeded` → payment done, webhook will fire.
- `awaiting_next_action` → 3DS test page (Continue), returns to the page; webhook still fires.
- `awaiting_payment_method` + `last_payment_error` → card rejected in test mode; page shows why.

> The old one-line popup (`PayMongo.create(clientKey).open()`, `js.paymongo.com/v1/paymongo.js`)
> **no longer exists** — removed from the CDN (verified on the live bundle 2026-08-05). The
> fetch-based page matches the current docs flow: create Payment Method (public key) → attach
> with `client_key` → handle 3DS. CORS from `127.0.0.1:8000` is verified open.

## Verify

```powershell
Get-Content C:\Users\akwean\Downloads\gw-system\backend\storage\logs\paymongo.log -Tail 20
```

Expect the chain: `webhook received` → `acknowledged: known event type` →
`PayMongo webhook processed: invoice marked paid {"invoice_id":1,"payment_id":"pay_…"}`.

```powershell
php artisan tinker --execute="print_r(App\Models\Invoice::where('id',1)->first(['id','status','paymongo_payment_intent_id'])->toArray()); print_r(App\Models\Payment::all(['id','invoice_id','amount','method','paymongo_reference','paid_at'])->toArray()); print_r(App\Models\ProcessedWebhookEvent::all(['event_id','event_type','processed_at'])->toArray());"
```

PASS = invoice `paid` · one Payment row (`method: paymongo`, `paymongo_reference: pay_…`) ·
one `processed_webhook_events` row. All timestamps are Asia/Manila (app timezone).

## Idempotency check

Dashboard → Webhooks → endpoint → Deliveries → **Resend** the `payment.paid` delivery (twice if
you like) → log shows `PayMongo webhook skipped: event already processed` each time, still one
Payment row. Re-POST `/pay` on the same invoice → 409 "Invoice is already paid."

## Gotchas learned

- Stale intent ids self-heal on `/pay` (4xx → fresh intent). A *succeeded* stored intent blocks
  `/pay` with 409 (double-charge guard) — check `paymongo:reconcile` for the "CHARGED BUT NOT
  CREDITED" finding before touching anything.
- `processed_webhook_events` table missing → `php artisan migrate`.
- No log entries at all after paying → queue worker not running; check the `jobs` table.
- `no invoice for intent` → stale/foreign intent (e.g. resending an old payment); make a fresh
  payment on the intended invoice.
- `signature verification failed` → dashboard webhook secret drifted from `.env`.
- Old failed deliveries in the dashboard resend automatically on retry — harmless, they log
  `no invoice for intent`.

---

## Addendum — email delivery verification (Payments item 4, Mailtrap)

> Added 2026-08-05 after the first full round (payment → webhook → mark paid → email with PDF
> attachment received in Mailtrap). Run this after any change to the mail/email-job code.

### Gotcha that WILL silently skip the email (hit 2026-08-05)

The email job sends only to portal users with an **active** `ConnectionLink` on the *paid
invoice's* connection. In the seeded dev DB only `test@example.com` → connection 1
(`GW-00001`/`MTR-00001`) is linked, and invoice 1 is already paid. Paying any other invoice
works (payment records fine) but logs
`Payment confirmation email skipped: no linked users with a valid email` — **no email is sent**.
Link the user to the target connection first (self-serve `/api/links`, needs `account_number` +
`meter_number` from the connection):

```powershell
$body = @{ email = 'test@example.com'; password = 'password' } | ConvertTo-Json
$login = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/login' -Method Post -ContentType 'application/json' -Body $body
$headers = @{ Authorization = "Bearer $($login.token)" }
$link = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/links' -Method Post -Headers $headers -ContentType 'application/json' -Body (@{ account_number = 'GW-00002'; meter_number = 'MTR-00002' } | ConvertTo-Json)
$link | ConvertTo-Json
```

### Prereqs (on top of the main doc's four)

1. Mailtrap account (free) with a Testing inbox; copy its SMTP creds (host `sandbox.smtp.mailtrap.io`,
   port `2525`, inbox-specific username/password) into `backend/.env`:
   `MAIL_MAILER=smtp` · `MAIL_SCHEME=null` · `MAIL_HOST=sandbox.smtp.mailtrap.io` · `MAIL_PORT=2525`
   · username/password · `MAIL_FROM_ADDRESS=noreply@example.com`.
2. **Restart `php artisan serve` and `php artisan queue:work --tries=3` after any `.env` mail
   change** — a worker started before the edit keeps the old config and "sent" logs appear with
   nothing in Mailtrap.
3. Optional: `APP_NAME=GW-System` so the From name isn't "Laravel".

### Smoke-check SMTP alone (isolates Mailtrap from the payment flow)

```powershell
cd C:\Users\akwean\Downloads\gw-system\backend
php artisan tinker
# >>> interactive prompt (--execute is flaky with closures):
Mail::raw('smtp check', fn ($m) => $m->to('test@example.com')->subject('smtp check'));
```

Message appears in mailtrap.io → **Email Testing → the SAME inbox whose creds you pasted** (creds
are per-inbox; wrong inbox = nothing visible).

### Full round (after the main doc's Phases: link → `/pay` → pay-checkout.html test card)

1. Pay an unpaid invoice **on the connection you linked** (e.g. invoice 2, `GW-2026-00002`).
2. Mailtrap inbox: "Payment received — Invoice GW-2026-00002" (subject) — body table (invoice
   number, billing period, amount paid, invoice total, date paid) + **Attachments tab:
   `invoice-GW-2026-00002.pdf`** (download, verify itemized breakdown).
3. `paymongo.log` — expected chain ending in
   `local.INFO: Payment confirmation email sent {"invoice_id":2,...,"recipients":["test@example.com"]}`.
   Note: `testing.INFO` entries in the same file are the phpunit suite (see below — fixed
   2026-08-05 so tests no longer write there).
4. Dashboard → Webhooks → Deliveries → **Resend** the `payment.paid` delivery → log
   `skipped: event already processed` → Mailtrap still shows **exactly one** message (no
   duplicate email).

### Timestamp gotcha (not a bug)

Mailtrap's inbox **list** displays UTC ("07:33") while the email header says
`Date: Wed, 05 Aug 2026 15:33:06 +0800` — the header is correct (Asia/Manila); the list view is
Mailtrap's account timezone display (change it in Mailtrap settings if it confuses you). The
only genuinely inconsistent rows in the dev DB are invoice 1's UTC-era `paid_at`/`processed_at`
from before the app timezone fix — known, dev DB gets wiped before prod.
