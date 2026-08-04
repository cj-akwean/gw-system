# Session Summary — 2026-08-04 (Payments: PayMongo webhook route — checklist item 2)

## Goal
Implement ARCHITECTURE.md Payments item 2: `POST /api/paymongo/webhook` — signature
verified, idempotent at the route level, acks <30s. Strict scope (user confirmed): route +
verification + livemode guard + unknown-event skip ONLY. The queued job
(`ProcessPayMongoWebhook`), dedupe table, mark-paid, and Payment row are item 3's session.

## Docs verification (AGENTS.md rule 6 — current PayMongo docs, not memory)
Fetched 2026-05/06-updated docs:
- **Signature scheme**: header `Paymongo-Signature`; **plain** HMAC-SHA256 of the raw
  request body with the endpoint secret, base64, timing-safe compare (`hash_equals`).
  The `t`/`te`/`li` timestamp-part idea in the old ARCHITECTURE note was Stripe-style
  and is wrong — corrected in ARCHITECTURE.md in this commit.
- **Ack**: 200–209 + JSON within 30s; PayMongo retries up to 12× on failure/timeout —
  so anything that *verifies* must be acknowledged, never 4xx/5xx.
- **Event shape**: `data.id` (event id), `attributes.type` (resource.action),
  `attributes.livemode`, `attributes.data` (resource snapshot).
- **Livemode guard** + separate test/live endpoints (dashboard config, manual step).

## Files created
- `backend/app/Http/Controllers/Api/PayMongoWebhookController.php` — `store()`:
  raw body via `$request->getContent()`; header `Paymongo-Signature` with
  `X-Paymongo-Signature` fallback (spelling received logged); verify → 401 on failure;
  malformed payload / livemode mismatch / unknown type → log + 200 `{received:true}`;
  known types (`payment.paid`, `payment.failed`, `payment_intent.succeeded`,
  `payment_intent.awaiting_payment_method`) → log + 200 ack (dispatch wiring = item 3).
- `backend/tests/Feature/PayMongoWebhookTest.php` — 11 tests.
- `docs/summary/2026-08-04-paymongo-webhook.md` (this file).

## Files modified
- `backend/app/Services/PayMongoService.php` — added
  `verifyWebhookSignature(string $rawBody, ?string $signature): bool` (base64 HMAC-SHA256
  + `hash_equals`; fails closed on missing/empty secret or signature; `paymongo` channel).
- `backend/routes/api.php` — `Route::post('/paymongo/webhook', ...)` OUTSIDE the
  `auth:sanctum` group (signature is the auth; no CSRF on api group).
- `ARCHITECTURE.md` — Payments item 2 checked with implementation notes + corrected the
  `t`/`te`/`li` signature note.

## Test results
- `php artisan test`: **123/123 pass, 383 assertions** (was 112/112, 366 — +11 tests,
  no regressions).
- `php -l` clean on all 4 changed files; Pint clean (fixed test file imports).
- `php artisan route:list --path=paymongo` → `POST api/paymongo/webhook`.

## Known gaps / caveats
- Route ack-skip only: known events are logged, NOT dispatched to a job yet — item 3
  adds `ProcessPayMongoWebhook` + `processed_webhook_events` dedupe + mark-paid + Payment
  row, and the controller's known-event branch becomes a dispatch call.
- The `Paymongo-Signature` header spelling was verified via docs; the real-world spelling
  on actual test webhook deliveries still needs confirmation (logged to
  `storage/logs/paymongo.log` on first real delivery — see manual verification below).
- Signature format assumption (plain base64 HMAC, no timestamp parts) is per current docs;
  if a real delivery shows a different header shape, adapt + log it in a summary.

## Manual verification the user should do
Detailed instructions in the chat response for this session (PayMongo dashboard webhook
registration needs a public HTTPS URL; PAYMONGO_WEBHOOK_SECRET in `backend/.env`;
check `storage/logs/paymongo.log` for the header spelling + skip reasons).

## Git state
NOT committed (per project rules — commit only on explicit request).

## Addendum — real-delivery bug found via manual verification: signature encoding is HEX, not base64

The user completed a real test payment (test Visa `4343434343434345` attached to
intent `pi_ejdxDscmZqfEDM5Du2GbeThi` → `succeeded`, payment `pay_Xjy1Fv4kArkPLrpk7rhn4D4E`).
The `payment.paid` event was delivered through ngrok and logged as
`PayMongo webhook received` — then **rejected** on every retry (8+ attempts) with
"signature verification failed", despite the endpoint secret being copied exactly.

**Root cause:** `verifyWebhookSignature()` encoded the HMAC as **base64**
(`base64_encode(hash_hmac(..., true))`). PayMongo's official sample
(docs.paymongo.com/docs/developer-tools-best-practices-1, updated 2026-05-07)
uses `.digest("hex")` — the signature header is **HEX**. The unit tests could not
catch this: they compute signatures with the same helper, so base64-in/base64-out
is self-consistent. Only a real PayMongo delivery exposes an encoding mismatch —
a textbook win for the "manually test money-critical flows" rule.

**Fix:** `hash_hmac('sha256', $rawBody, $secret)` (hex, default output) in both the
service and the test helper. Docs updated: ARCHITECTURE.md item 2 note +
product-decisions §15 addendum. Full suite re-run after the change.

**Also confirmed during this manual round:** header spelling is `Paymongo-Signature`
(not `X-Paymongo-Signature`); the webhook delivery entry stuck in "loading" was
PayMongo's retry state (up to 12 attempts, exponential backoff) after our 401s —
it resolves to delivered once the signature passes.

**User action after this fix:** restart `php artisan serve`, hit **Resend** on the
failed delivery in the dashboard, expect `acknowledged: known event type` +
`payment.paid` in `storage/logs/paymongo.log`.

## Addendum 2 — the plain-HMAC conclusion was ALSO wrong: the signature is timestamped (`t`/`te`/`li`)

The hex fix above was deployed (file mtime 2:03 PM, serve restarted 2:07 PM) and a
Resend at 2:10–2:13 PM was **still rejected** — the webhook log showed
"signature verification failed" for every attempt (30+ total). At the same time the
user pasted the dashboard's signing secret (`whsk_QDYVWcdSDdZHGcCNCQ2Rt2Rn`) and the
registered endpoint URL (`https://148b-110-54-167-206.ngrok-free.app/api/paymongo/webhook`);
the secret matched `backend/.env` byte-for-byte (29 chars, `whsk_` prefix, no
whitespace/quotes) — so the secret and endpoint were correct all along.

**Final root cause (found 2026-08-04 via docs.paymongo.com/docs/developer-tools-webhook-setup-management):**
the `Paymongo-Signature` header is `t=<unix timestamp>,te=<test sig>,li=<live sig>`.
The signed string is `"<t>." . $rawBody` (timestamp + period + raw body), the digest is
HMAC-SHA256 hex with the endpoint secret, and you compare against `te` (test mode) or
`li` (live mode). Both earlier implementations (base64 of the body; hex of the body
alone) failed real deliveries for the same reason: they missed the `<t>.` prefix and
the `te`/`li` selection, and the unit tests were self-consistent (helper signed in the
same wrong format). The docs pages consulted earlier omitted the header's literal
shape; the setup-management page shows it.

**Fix:** `verifyWebhookSignature()` now parses `t`/`te`/`li` (trimmed, fail-closed on
missing parts), computes `hash_hmac('sha256', $t.'.'.$rawBody, $secret)`, and
`hash_equals` against `te` (or `li` when `PAYMONGO_LIVEMODE=true`, passed from the
controller). Test helper emits the real format; 8 new regression tests: body-only HMAC
→ 401, base64 digest → 401, header/body timestamp mismatch → 401, missing `t` → 401,
missing `te` → 401, whitespace tolerance, live-mode `li` selection (config livemode=true),
test-signature-when-live-configured → 401.

**Test results after fix:** `php artisan test` **131/131 pass, 392 assertions**
(was 123/123, 383); php -l clean; Pint clean.

**User action after this fix:** restart `php artisan serve`, hit **Resend** on the
failed delivery (or wait for an automatic retry), expect
`acknowledged: known event type {"event_type":"payment.paid"}` in
`storage/logs/paymongo.log` and the delivery to flip to delivered. If it still fails,
the next diagnostic is temporary debug logging (received vs computed signatures) —
but the implementation now matches PayMongo's documented format exactly.

**Deferred (noted, optional per docs):** timestamp freshness / replay-window check on
`t` — deferred to hardening; the item-3 dedupe table covers retries.

## Next recommended step (unchecked item)
Payments item 3 — Invoice marked paid on webhook confirmation:
`App\Jobs\ProcessPayMongoWebhook` (ShouldQueue, tries=3) + `processed_webhook_events`
table (unique event id) + find invoice by `paymongo_payment_intent_id` + create `Payment`
row (method `paymongo`, amount, `paymongo_reference` = intent id, `paid_at`) + set
invoice `status = paid` (only `unpaid → paid`); `payment.failed`/expiry → log only.
Controller's known-event branch dispatches the job.
