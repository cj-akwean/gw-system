# 2026-08-08 — Payment-return recovery + confirmed-payment feedback (3DS "not available" bug)

## Goal

Fix the recurring bug where a successful card + 3DS payment landed the user on
"This bill isn't available for payment right now." even though the webhook had paid
the invoice (logs: invoice 15 paid 07:55 today, receipt emailed), and give users a
clear confirmation when the payment actually went through. Senior-review pass on the
redirect-return handling per the plan (backend endpoint approved by the user).

## Symptom → root cause chain (verified, not guessed)

- User: card `4120…0007` (3DS) → Authorize → redirected to `http://localhost:3000/dashboard/pay`
  (bare — user's address bar) → "This bill isn't available" + "Check my bills".
- The not-payable panel renders only for `invoiceId === ""` (or a definitive 403/404 on a
  known id). Bare URL ⇒ `idParam` null ⇒ recovery depended on the sessionStorage pending
  marker (`gw-pending-invoice` written right before the redirect) — which returned null ⇒
  **sessionStorage did not survive the 3DS round trip** (per-tab storage; the return can
  land in a new tab/context). The docs also say PayMongo appends `payment_intent_id` to
  the return URL — the previous code assumed it preserved our query or stripped
  everything, and the tests encoded those wrong shapes, so they were green while the bug
  persisted.
- Secondary: an empty/unidentifiable id rendered a *definitive* dead-end instead of an
  unknown "keep checking" state, and success feedback on return was a pending banner at
  best until the 15s poll caught the credit.

## Files created or modified

Backend:
- `app/Http/Controllers/Api/PaymentController.php` — new `intentStatus()`: resolves
  `payment_intent_id` → invoice (ownership-gated), returns `paid` / `confirmed`
  (PayMongo succeeded, webhook lagging; carries `invoice_id`) / `failed` /
  `processing` / `unknown` (no local invoice — never 404); 403 only foreign, 502 gateway.
- `routes/api.php` — `POST /payments/intent-status` (auth:sanctum, `throttle:30,1`).
- `app/Services/PayMongoService.php` — `getStoredPaymentIntent` treats **404/410 only** as
  stale → fresh intent; other 4xx (429/401) throw → 502 (closes a narrow double-charge
  window: a live-but-rate-limited intent is never silently replaced).
- `tests/Feature/PaymentIntentStatusApiTest.php` (NEW, 11 tests); `PayMongoServiceTest`
  +1 (429 → throw, no fresh intent).

Frontend:
- `lib/api.ts` — `resolveIntentStatus()`; pending marker now `{invoiceId, writtenAt}` in
  **sessionStorage AND localStorage** (1-h TTL, not cleared on read — StrictMode-safe;
  backend ownership guards whatever it recovers).
- `app/dashboard/pay/page.tsx` — parses PayMongo's `payment_intent_id`/`status`; lazy
  one-shot marker read at mount; `returnedFromRedirect` = marker OR any PayMongo return
  param; clears stale markers on id-carrying visits.
- `components/portal/payment-method.tsx` — resolution path for intent-only returns:
  `paid`/`confirmed` → immediate **"Payment received"** panel (confirmed shows a
  "confirmed with your payment provider…" note and polls `getInvoice` until the webhook
  credits, then flips to the receipt text); `failed` → "Payment didn't go through" panel
  with Try again (rebuilds the pay screen via the recovered invoice id); `processing`/
  `unknown`/5xx → checking panel whose poll re-resolves the intent. Empty id →
  checking panel (lazy-initialized) — **never** not-payable; not-payable is now 403/404-
  only on a *known* invoice id.
- Tests: `page.test.tsx` rewritten (real return shapes `?payment_intent_id=…&status=…`,
  localStorage new-tab recovery, TTL, URL-wins); `payment-method.test.tsx` +9 (paid/
  confirmed/failed/processing/unknown/5xx intent paths, empty-id checking regression);
  `api.test.ts` intent-status + dual-storage/TTL marker tests.

Docs: `ARCHITECTURE.md` (Payments §1 stale-404/410-only note + Customer Portal §4
recovery addendum), `docs/insights/implementation-notes.md` §4 addendum, this summary.

## Verification

- Backend: **512/512, 2,257 assertions** (`php -d memory_limit=512M
  vendor/phpunit/phpunit/phpunit`; +13 new). Frontend: **107/107** (`npm test`).
  `npm run build` passes (`/dashboard/pay` static). Lint: **no new errors** — the 8
  remaining errors are pre-existing in untouched files (opening-animation,
  theme-toggle, hero-33, use-elastic-line-events, auth-context, dotmatrix-hooks).
- NOT yet verified manually (needs the browser + test-mode PayMongo): the live 3DS round
  trip with the 4120 card — confirm the exact return URL in devtools, paid panel appears
  immediately, webhook credits, email arrives. **ngrok webhook URL must be current
  first** (stale tunnel = payments succeed but never credit locally).

## Known gaps / next step

- The pending marker has no per-user validation (shared browser: another user could
  recover an invoice they don't own → backend ownership returns 403/404 → safe panels;
  TTL caps it at 1 h). Accepted tradeoff to keep the recovery lint-clean; noted in
  implementation-notes.
- Old-intent webhook matching after an intent is replaced is still covered only by
  `paymongo:reconcile` (known, unchanged).
- Next step: live test-mode round trip (card 3DS + GCash + QR Ph) with the current ngrok
  URL, then a commit of this checklist item + `graphify . --update` (new controller
  method only, no new classes — graph update optional).

## Git state

NOT committed (per project rules).

## Addendum 8 — One unified payment-outcome flow + refresh/connection-loss resilience (same day)

**Goal (user ask):** every payment shares one consistent success process — confirming
modal (no escape) → success modal with OK → dashboard — for card, 3DS, GCash, and the
frictionless path alike.

**Changes (`payment-method.tsx`, `pay/page.tsx` + tests):**
- **Intent resolution is authoritative and first:** any visit carrying a
  `payment_intent_id` (URL param or pending record) resolves server-side before the
  unpaid-list path — so a GCash/3DS return that lands before the webhook credits shows
  the confirming modal, never the interactive pay screen. `processing` intent now maps to
  the confirming modal (was the unconfirmed checking panel).
- **Frictionless card (`succeeded` or `processing` attach):** writes the pending record
  (intent id + method) and resolves immediately → confirming/success modal; the flow
  stays `busy` until the outcome lands (no double-pay; the modal's backdrop also blocks
  the screen). The old `cardProcessing` spinner panel and the "Payment in progress"
  pending banner are removed (the modal replaces both).
- **Refresh resilience:** the pay page reads the pending record whenever the URL lacks
  `payment_intent_id` too (per-field precedence — a frictionless refresh keeps `?id=X`
  but must recover the intent from storage); the clear-on-id-visit effect is gone (TTL +
  paid-clear guard staleness). Refresh during ANY outcome modal re-mounts into the same
  modal family.
- **Connection loss:** the confirming poll never flips the outcome on network errors;
  after 2 consecutive failures the modal shows "Having trouble reaching the server —
  retrying…", cleared on the next success. Server-side (webhook job + receipt email) is
  unaffected by browser connectivity.
- Tests: processing → confirming modal; redirect-return-with-unpaid-invoice+intent →
  confirming modal (not the ready screen); frictionless processing → confirming +
  pending written; connection-loss → modal persists + hint → recovers to success;
  page recovers the pending intent when the URL has id but no intent. Frontend 123/123,
  backend 512/512, build static, lint clean. (The one test-authored quirk: React 19
  flushes microtask-driven state outside `act`, so the hint assertion awaits `vi.waitFor`.)

## Addendum 7 — Confirming modal can no longer strand the success confirmation (same day)

**Symptom:** clicking "Back to my bills" inside the confirming modal navigated away mid-
confirmation — and the success modal never appeared (a paid bill has no Pay button to
return to, so the confirmation was lost forever).

**Fix (`payment-method.tsx` + tests):**
- The ConfirmingModal's escape button is **removed** — it now shows spinner + "This
  usually takes a few seconds." and auto-flips to the success modal. The confirming poll
  runs on a faster 5s interval (`CONFIRMING_POLL_MS`) instead of 15s, so the flip is
  snappy. The only way to leave mid-confirmation is a browser refresh, which re-resolves
  (paid → success modal; confirmed → confirming modal again) — never a permanent trap.
- Test updated: confirming modal has no back button; flip happens within the 5s poll.
  Frontend 123/123, backend 512/512, build static, lint clean.

## Addendum 6 — Frictionless-card feedback + double-pay guard (same day)

**Symptom:** the no-3DS card (`4343…4345`) "just went back to the page" — no processing
indication, Pay button re-enabled, a second click could re-attempt payment.

**Fix (`payment-method.tsx` + tests):**
- `busy` now includes `cardProcessing` — the moment a card charge moves (succeeded /
  processing), the Pay button and all method rows disable until the outcome resolves
  (double-pay guard on top of the backend's existing 409 intent guard).
- The processing note is a visible spinner panel; it hides once the payment resolves
  (same `paymentResult === null` rule as the pending banner).
- **Frictionless `succeeded` attach now resolves the intent immediately** —
  `resolveIntentStatus` → `paid` → success modal right away; `confirmed` → confirming
  modal (webhook lag); failure → the processing note stays and the 15s poll resolves it.
  No more silent wait.
- Tests: +2 (succeeded→confirmed confirming modal + locked Pay; succeeded→paid success
  modal immediately) and the processing-note test now asserts the disabled button and
  no intent call for `processing`. Frontend 123/123, backend 512/512, build static, lint
  clean.

## Addendum 5 — Success is an explicit modal with an OK button (no auto-redirect) (same day)

**Ask:** the toast could be missed and the auto-redirect moved on without the user; the
"Payment in progress" banner stayed visible under the toast, contradicting it. Fix per
user: success = **modal** (dimmed backdrop, centered card, check icon, receipt line) with
a single **OK** button → `/dashboard`. **No auto-redirect** (the 4s timer +
`TOAST_REDIRECT_MS` removed — nothing navigates until the user clicks). The pending
banner now renders only while `paymentResult === null`, so it disappears the moment the
payment resolves (no conflicting messages). Confirming modal unchanged (spinner + "Back
to my bills"). Tests: success paths assert the modal + OK-click navigation + no
auto-redirect (timers advanced, `push` NOT called before the click); +1 test that the
pending banner is hidden once resolved. Frontend 121/121, backend 512/512, build static,
lint clean.

## Addendum 4 — Card vaulting disabled (PayMongo account capability), card flow restored (same day)

**Symptom:** every card payment → "Payment couldn't start — Payment gateway unavailable"
(502, retried 3×). `paymongo.log`:
`PayMongo create payment intent failed: {"code":"parameter_invalid","detail":"On session
payments are not yet supported."}` — fired only on the save-card path
(`setup_future_usage: {session_type: "on_session", customer_id}`).

**Root cause (docs-verified, payment-acceptance-card-vaulting 2026-05-07):** the vaulting
API is exactly what the code sends — but "**Card Vaulting is only available for select
accounts. Contact support@paymongo.com to request for configuration.**" The test account
lacks the capability, so every vaulting intent is rejected. The plain intent path
(`vaulting:false`) works (log: intents created 17:19/17:35; customer `cus_fae6…` created
17:36 after the last_name/default_device fix).

**Fix — vaulting disabled, card flow restored:**
- Frontend: save-card checkbox removed from `card-form.tsx` (`saveCard` dropped from
  `CardPayload`); `startCard` uses plain `startPayment(invoiceId)`; the entire dead
  saved-card UI removed from `payment-method.tsx` (SavedCardSelector, saved-cards fetch
  effect, `startSavedCard`, `handleDeleteCard`, related states/imports). `api.ts`
  `startPayment` drops the `save_card` option and the `save_card`/`customer_id` response
  fields.
- Backend: `InvoicePaymentController@store` ignores any `save_card` body — always the
  plain intent path (stale clients can't trip the vaulting rejection). The saved-method
  endpoints + `PayMongoService` vaulting methods remain (tested; re-enable the UI in the
  same release as the PayMongo account capability).
- Frontend 120/120, backend 512/512, build static, lint clean (known img warning only).

## Addendum 3 — Toast is an overlay (no white page), slower auto-return, saved-cards 502 fixed (same day)

**UX fixes (`payment-method.tsx` + tests):**
- The success feedback was a full-page swap (`bg + toast`) — looked like its own page
  with a white flash. Reworked: `paid` is no longer a screen state at all; a separate
  `paymentResult` overlay renders on TOP of whatever screen is current (the pay screen
  with method cards / QR stays visible underneath). All early-return branches wrap the
  overlay. Auto-return delay bumped 3s → 4s, with a fade-in (tw-animate-css
  `animate-in fade-in slide-in-from-top-2`). Test: poll-paid keeps `method-card-ewallet`
  visible under the toast.

**502 on `GET /api/saved-payment-methods` (backend, fixed):**
- Root cause from `laravel.log`: `PayMongo create customer failed:
  {"errors":[{"code":"invalid_request_body",...,"pointer":"data.attributes.last_name"},
  {...,"pointer":"data.attributes.default_device"}]}` — the customers API now REQUIRES
  `last_name` and `default_device` ("phone"|"email") per the current Create Customer
  reference (verified 2026-08-08). `getOrCreateCustomer()` lacked both → 422 → caught →
  502.
- Fix: payload gains `last_name` (name split on the last space; single-word names repeat
  as last so the field is never empty, `splitName()` helper) and `default_device: "email"`.
  This unblocks selecting Card (the saved-methods fetch no longer errors) and lets the
  card/3DS flow be tested.

Frontend 120/120, backend 512/512, build static, lint clean.

## Addendum 2 — Success feedback is a toast + auto-return, confirming is a modal (same day, user UX feedback)

**Ask:** after GCash authorize the flow worked (pending banner → "Payment received"), but
the success state replaced the whole screen and forced a "Back to my bills" click — bad UX.
User chose: **toast + auto-redirect** on success; **modal until credited** for the
webhook-lag window.

**Changes (`payment-method.tsx` + tests):**
- Full-screen `paid-panel` removed. `paid && !confirming` → fixed **success toast**
  ("Payment received — your confirmation and receipt are emailed to …",
  `role="status"`) + auto `router.push("/dashboard")` after 3s (effect with timeout
  cleanup — no double navigation).
- `paid && confirming` → compact **confirming modal** ("Payment confirmed with your
  provider — we're updating your account…", spinner, "Back to my bills" escape hatch);
  the existing poll flips it to the toast when the webhook credits.
- Tests: all six paid-path tests reworked (toast text, confirming→toast transition, 3s
  auto-redirect via fake timers, no `paid-panel` testid remains). Frontend 120/120,
  backend 512/512, build static, lint clean.

## Addendum — Return-context hardening (same day, applied on user report of bare `/dashboard/pay`)

**Confirmed app-side context destroyer:** `PaymentMethodScreen` unconditionally ran
`window.history.replaceState({}, "", window.location.pathname)` whenever
`returnedFromRedirect` was true — wiping ALL query params (`id`, `payment_intent_id`,
`status`) after mount. A bare `/dashboard/pay` after 3DS was therefore OUR cleanup, not
necessarily PayMongo stripping. Combined with the empty-context branch (`invoiceId=""`,
`paymentIntentId=null`) polling a hardcoded `Promise.resolve({status:"unknown"})`, refresh
stuck on "Checking your payment status…" forever with no real retry.

**Changes:**
- `payment-method.tsx` — removed the `replaceState` effect entirely (the URL keeps its
  params; the return banner is harmless on refresh). New `context-missing` screen state
  (rendered for no-id + no-intent visits; never not-payable, never an endless spinner;
  primary action Check my bills). Pending marker is cleared when a payment resolves `paid`.
- `api.ts` — pending record now carries `paymentIntentId` + `method` alongside
  `invoiceId` (server-side intent-status correlation survives even a bare return); read
  returns the full record.
- `pay/page.tsx` — `paymentIntentId = url ?? pending.paymentIntentId ?? null`; Suspense
  fallback replaced with a visible "Loading payment status…" panel (no more blank page).
- `writePendingInvoice` call sites (gcash / card / saved-card) pass the intent id + method.
- Tests: pending round trip (new shape), storage-carries-intent, context-missing panel,
  no-call guarantee; 120/120 frontend, 512/512 backend, build static, lint clean (only
  the known img warning; also removed a dead `selectedSavedCard` state).
- Phase 3 (server-side intent-status resolution) was already in place and is unchanged.
