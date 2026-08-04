# Session Summary — 2026-08-04 (Payments: PayMongo payment intent creation — checklist item 1, Phase 3)

## Goal
Implement the first Payments-phase checklist item: create a PayMongo payment intent
(one-off) per invoice, exposed through a Sanctum API endpoint. This session covers
ARCHITECTURE.md's "PayMongo integration (create payment intent/checkout)" sub-bullets
only — webhook, mark-paid, PDF email are separate sessions.

## Docs verification (AGENTS.md rule 6 — no guessing from memory)
PayMongo restructured their docs (docs.paymongo.com moved to developers.paymongo.com;
the old docs.paymongo.com/docs/payment-* URLs 404). Verified against the current pages:
- Create Payment Intent: `POST https://api.paymongo.com/v1/payment_intents`, HTTP Basic
  auth `base64(secret_key + ':')` (empty password), body `data.attributes.{amount
  (integer centavos), currency ("PHP"), payment_method_allowed (array), description,
  metadata}`, min amount 100 centavos (₱1.00). Response: `data.id` +
  `data.attributes.client_key` (confirmed via the Payment Intent Resource reference —
  client_key sits under attributes, NOT at data top level).
- Go-live checklist (developer-tools-go-live-checklist): POSTs that create data should
  send `Idempotency-Key`; webhook ack <30s + queued processing (next session).

## Files created
- `backend/app/Services/PayMongoService.php` — `createPaymentIntent(Invoice, array
  $methods = ['qrph','gcash','card'])` (POST + `Idempotency-Key: invoice-pay-{id}`,
  amount via `toCentavos()` = `round(total*100)`, metadata carries invoice_id +
  invoice_number, persists `paymongo_payment_intent_id` on the invoice) and
  `getPaymentIntent(string $id)` (GET, returns client_key) for reusing an existing
  intent on retry. Throws `RuntimeException` on API failure / malformed response /
  missing secret key; `ConnectionException` propagates for network failures.
- `backend/app/Http/Controllers/Api/InvoicePaymentController.php` — `store()`:
  active-link check (403), already-paid check (409), reuses stored intent id if
  present else creates one, 502 + `report()` on PayMongo/network failure, 200 with
  `{client_key, payment_intent_id}`.
- `backend/database/migrations/2026_08_04_000001_add_paymongo_payment_intent_id_to_invoices_table.php`
  — nullable string(100) after `status` on `invoices`; applied to dev DB.
- `backend/tests/Feature/PayMongoServiceTest.php` (8 tests) and
  `backend/tests/Feature/InvoicePaymentEndpointTest.php` (7 tests).

## Files modified
- `backend/.env.example` — added the four `PAYMONGO_*` vars (test-mode default note).
- `backend/config/services.php` — `paymongo` entry (secret/public/webhook_secret/livemode).
- `backend/app/Models/Invoice.php` — added `paymongo_payment_intent_id` to `#[Fillable]`.
- `backend/routes/api.php` — `POST /api/invoices/{invoice}/pay` inside the Sanctum group.
- `ARCHITECTURE.md` — Payments item 1 checked with implementation notes.

## Test results
- New tests: 15/15 pass (34 assertions). Full suite: **103/103 pass, 329 assertions**
  (was 88 tests — +15, no regressions).
- `php -l` clean on all changed files.
- `php artisan migrate --force` applied to dev Postgres; `php artisan route:list
  --path=invoices` shows `POST api/invoices/{invoice}/pay`.

## Design decisions (worth recording)
- **Retry safety**: the pay endpoint is idempotent-ish — an invoice that already has a
  `paymongo_payment_intent_id` triggers a GET of the existing intent (fresh client_key)
  instead of creating a zombie intent; the POST also carries `Idempotency-Key` so a
  double-submit race can't create two intents even when both hit the create path.
- **Ownership gate**: invoice must belong to a connection the user has an *active* link
  to (same rule as the links API) — 403 otherwise. Overdue invoices are payable
  (only `paid` is rejected, 409).
- **Amount precision**: `(int) round($total * 100)` — float safety before the cast.
- Deferred to webhook session: `PAYMONGO_LIVEMODE` guard, signature verification,
  processed-event dedupe, test-vs-live endpoint separation.

## Manual verification the user should do
1. Set `PAYMONGO_SECRET_KEY`/`PAYMONGO_PUBLIC_KEY` (test keys) in `backend/.env`.
2. With Laravel serving: `curl -X POST http://127.0.0.1:8000/api/invoices/{id}/pay` with
   a Sanctum Bearer token for a user linked to that invoice's connection → expect
   `{"client_key":"...","payment_intent_id":"pi_..."}` and the intent id stored on the
   invoice row. Re-call it → GET path, same client_key, no second intent (check the
   PayMongo dashboard's payment intents list).
3. An invoice NOT linked to the caller → 403; a paid invoice → 409.
4. Optionally curl the endpoint with no token → 401.

## Known gaps / caveats
- `getPaymentIntent()` result is returned blindly — if the stored intent is already
  `succeeded` (webhook lag), the frontend will still get a client_key; reconciliation
  is the webhook phase's job.
- The LSP warning "Undefined method createToken" on AuthController is a pre-existing
  IDE/static-analysis false positive (Sanctum trait) — not introduced here.

## Addendum — SSL fix + real test-mode round trip (same day, after user's 502)

- **User hit `502 Payment gateway unavailable` in Thunder Client.** Root cause was NOT
  code: `laravel.log` showed `cURL error 60: SSL certificate ... unable to get local
  issuer certificate` — Windows PHP (winget build 8.5.8) has no CA bundle, so PHP's
  cURL can't verify `api.paymongo.com`'s TLS cert.
- **Fix (environment, no repo code):** downloaded `cacert.pem` from curl.se into the
  PHP install dir and set `curl.cainfo` + `openssl.cafile` in its `php.ini`; verified
  with a dummy-key POST that now returns HTTP 401 JSON (TLS verified) instead of error
  60. Recipe documented in README.md → "PHP HTTPS / SSL" + one-line pointer in
  AGENTS.md (per user: avoid "works on my machine").
- **Real end-to-end verification:** ran `PayMongoService::createPaymentIntent` against
  the real PayMongo test API — created `pi_pE6ebMrs5vsPz6HUU16kJJik` for invoice #1
  (GW-2026-00001, ₱40.00), client_key returned, intent id persisted on the invoice.
  So the earlier "no real round trip" gap is now closed; retrying invoice #1 in
  Thunder Client exercises the GET-reuse path (idempotency), any other unpaid invoice
  exercises the create path.
- User action required: restart `php artisan serve` (php.ini is read at startup) before
  retrying in Thunder Client.

## Addendum 2 — 401-vs-500 on unauthenticated calls (Thunder Client `Accept` gotcha) + code fix

- **User verified the pay flow works** (client_key + intent id returned; 409 already-paid
  guard confirmed). But unauthenticated calls returned **500 `Route [login] not defined`**
  instead of 401 — while the code path is correct, Thunder Client sends a default
  `Accept: */*` header, and adding a second `Accept: application/json` row on top made the
  first header win → `expectsJson()` false → `Authenticate::redirectTo()` → `route('login')`
  (unnamed) → `RouteNotFoundException`. Removing the default row (editing it to
  `application/json`) yields the proper `401 {"message":"Unauthenticated."}`.
- **Code fix applied:** `routes/api.php` — `POST /api/login` now `->name('login')`, so
  headerless requests redirect instead of 500ing. Tested: full suite still 103/103.
- **Documented:** README.md → "Testing the customer API (Thunder Client / REST clients)"
  (header rules + smoke sequence + edge-case table), ARCHITECTURE.md Auth section note,
  and this addendum. No `Accept` header fix was needed in app code — the frontend (fetch)
  already sends the correct header.
- Git state: still NOT committed (includes: PayMongo intent feature, README/AGENTS/ARCH
  doc changes, this route name fix).

## Git state
NOT committed (per project rules — commit only on explicit request). HEAD before this
session: `d95f2af`.

## Next recommended step (unchecked item)
PayMongo webhook route — signature-verified + idempotent: `POST /api/paymongo/webhook`
(no Sanctum/CSRF, ack <30s), HMAC-SHA256 of the **raw** body with
`PAYMONGO_WEBHOOK_SECRET`, log whether `Paymongo-Signature` or `X-Paymongo-Signature`
arrives, guard livemode, unknown event types → ack+skip. Spec: ARCHITECTURE.md
Payments item 2 + `docs/prompts/payments-customer-portal-flow.md`.
