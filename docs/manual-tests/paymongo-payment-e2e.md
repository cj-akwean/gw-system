# Manual Test — PayMongo payment E2E (webhook → invoice marked paid)

> Written 2026-08-05 after the first successful end-to-end round (test mode).
> Purpose: verify the whole chain — `/pay` intent creation → customer completes payment →
> `payment.paid` webhook → `ProcessPayMongoWebhook` job → invoice `paid` + `Payment` row →
> dedupe on redelivery. Run this after any change to the payment/queue/webhook code.

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
