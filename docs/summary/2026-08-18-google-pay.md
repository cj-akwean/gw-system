# 2026-08-18 — Google Pay (digital wallet) via PayMongo

## Goal

Per `.kilo/plans/1787047151001-paymongo-google-pay-blueprint.md` (source of truth):
turn the unchecked `Digital Wallet (Google Pay)` item in ARCHITECTURE.md (line 265) into a
real payment flow — official Google Pay button as the single tap-to-pay trigger, tokenized
client-side with the PayMongo public key, reusing the existing card recovery machinery with
zero new server logic beyond accepting the `google_pay_card` PM type.

## Files created / modified

| File | Action | What |
|---|---|---|
| `backend/app/Services/PayMongoService.php` | modified | `VALID_PAYMENT_METHODS` + `createPaymentIntent()` default methods gain `google_pay_card`. |
| `backend/tests/Feature/PayMongoServiceTest.php` | modified | Default-methods assertion → `['qrph','gcash','card','google_pay_card']`. |
| `frontend/src/lib/paymongo.ts` | modified | `publicKey()` exported (single source of truth for the button's env/gateway id). |
| `frontend/src/types/payjs.d.ts` | new | Minimal ambient Google Pay types (`PaymentsClient`, `createButton`, `isReadyToPay`, `loadPaymentData`, requests/responses) — Google ships none. |
| `frontend/src/components/portal/google-pay-button.tsx` | new | Official-button component: dynamic script loader (module promise cache, 8s timeout, sync-resolve when API present), secure-context gate, `isReadyToPay` (PAN_ONLY, VISA/MC, MIN billing + email), env from `publicKey()` prefix, `gatewayMerchantId` = public key, `merchantInfo.merchantId` = "TEST"/env, `loadPaymentData` → `onToken`, inline error (silent on CANCELED), disabled/unavailable placeholders, busy-ref double-tap guard. |
| `frontend/src/components/portal/payment-method.tsx` | modified | `googlepay` in `selectedMethod`/`QrState` flow; `startGooglePay` mirrors `startCard` (intent → `google_pay_card` token PM → attach with return_url → redirect | intent-status resolve); Digital Wallet card is now a tappable button (disabled by `busy` only — NOT the e-wallet cap); review shows the Google Pay button instead of Swipe for `googlepay` (`payBlocked` per-method: Google Pay exempt from `capExceeded`); "Paying with: Google Pay"; busy texts "Starting/Connecting to Google Pay…"; aside hint "Tap the Google Pay button to continue."; cap note now says "Use Card or Google Pay for this bill."; error retry resets googlepay like card. |
| `frontend/src/components/portal/payment-method.test.tsx` | modified | Mock for `GooglePayButton`; `selectMethod`/`goToReview` accept `googlepay`; card-render test (digital wallet enabled, no "Coming soon"); cap test asserts Google Pay stays enabled + trigger reachable over ₱100k; +3 flow tests (token PM + attach payload, 3DS redirect + pending marker, succeeded → intent-status → success modal). |
| `frontend/src/components/portal/google-pay-button.test.tsx` | new | 7 tests using a stubbed global `PaymentsClient` (loader sync-resolve): script missing → unavailable (8s timeout), missing key → unavailable, isReadyToPay no → unavailable, tap → token + billing mapping out, non-cancel error inline / cancel silent, disabled no-op, env + gatewayMerchantId from `pk_test_`. |
| `frontend/src/components/portal/past-payments.tsx` | modified | `google_pay_card` / `googlepay` → "Google Pay". |
| `frontend/src/components/portal/past-payments.test.tsx` | modified | Label assertions for both channel variants. |
| `frontend/.env.example` | modified | `NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID=` (live rollout only). |
| `ARCHITECTURE.md` | modified | Line 265 checked off with details pointer → Payments §8. |
| `docs/insights/implementation-notes.md` | modified | New Payments §8 (token flow, env derivation, single-trigger, cap exemption). |
| `docs/insights/product-decisions.md` | modified | §54: GPay-button-as-single-trigger (vs Swipe) + card-based cap exemption + single-source env. |
| `docs/prompts/payments-customer-portal-flow.md` | modified | Digital Wallet row now the implemented flow (was "Coming soon until verified"). |
| `docs/manual-tests/paymongo-payment-e2e.md` | modified | New "Google Pay round" section (test-card steps + no-test-card fallback + simulator note). |
| `docs/showcase/README.md` | modified | "Digital Wallet / Google Pay" removed from the deferred-features list. |

## Bugs found & fixed

1. **Ref writes during render tripped `react-hooks/refs` (3 errors).** The first
   `GooglePayButton` kept latest props by writing `ref.current` in the render body. Fixed
   with the effect-sync pattern (`useEffect` with no deps updates `onTokenRef` /
   `disabledRef` / `amountRef`) — reconstruction linted clean.
2. **happy-dom has no `isSecureContext`** (it's `undefined`). The plan's `!window.isSecureContext`
   gate would have made every unit test "unavailable". Kept behavioral equivalence in real
   browsers by gating on `window.isSecureContext === false` only (undefined/true → proceed).
3. **Async-bootstrap race in the new component tests.** `render()` only flushes the sync
   part of the bootstrap; the `PaymentsClient` is constructed and the button mounted in
   microtasks afterwards, so immediate `getByTestId`/`querySelector` reads flaked. Fixed
   with polling helpers (`latestClient()` and `mountButton()` wait via `vi.waitFor`).
4. **Component test clicked without mocking `loadPaymentData`** → `.then` on `undefined`
   TypeError. Mocked it in the environment-flag test before clicking.
5. **Blueprint gap: `loadPaymentData` requires `transactionInfo`** (total price) but the
   planned component API was `{ onToken, disabled }`. Added an `amount` prop (pesos) so the
   button can build a valid request; `payment-method.tsx` passes `invoice.total_amount`.

## Test results

- Backend: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter PayMongoServiceTest`
  → **26/26 (+ 51 assertions) green**.
- Frontend: `npm test` → **232/232 green across 23 files** (baseline was 222/20; +7 new
  component tests, +3 payment-method flow tests, label-test extended).
- `npm run lint` → 11 problems (6 errors + 5 warnings): the errors are all pre-existing
  (`setState-in-effect` in auth-context/otp-input/elastic-line/payment-method, one
  unescaped apostrophe in reset-password); one NEW warning mirrors the pre-existing
  `startCard` pattern (missing `healthStatus` dep on `startGooglePay`).
- `npm run build` → clean (TypeScript + static export, 11 routes).

## Known gaps / next step

- **Not verified live**: PayMongo dashboard Google Pay account capability, Google Pay
  Console business verification, real sheet with an enrolled test card, live tokens. The
  dev-machine round can certify the client + attach half via the new unit suites; the
  webhook half via `php artisan paymongo:simulate-payment {invoice} --source=google_pay_card`.
- `NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID` must be set at live rollout (stays empty in test).
- `graphify . --update --code-only` ran (new component + shared `payment-method.tsx`/
  `paymongo.ts` touched) + `graphify cluster-only` regenerated GRAPH_REPORT.md (418
  communities; no LLM key on this machine, so doc-semantic extraction was skipped and
  instead the code-only AST graph was refreshed) — no unexpected churn.
- Not committed (user hasn't asked). Commit hash: pending.

## Addendum (same day) — §4 dev simulate harness

The plan grew a §4: a dev-only test harness so the webhook-crediting half can be exercised
from the browser when the GPay sheet can't open on the dev laptop (PayMongo has no
`test_url` simulator for `google_pay_card`, unlike QR Ph).

| File | Action | What |
|---|---|---|
| `backend/app/Services/PaymentSimulationService.php` | new | `simulate(Invoice, source, ?payer)`: payload-build + synchronous `ProcessPayMongoWebhook::handle()` extracted from the CLI; `InvoiceNotPayableException` on non-payable; `pi_sim_` fabrication; returns `[payment_id, event_id, payer]`. |
| `backend/app/Console/Commands/PayMongoSimulatePaymentCommand.php` | modified | Delegates to the service (same signature/output/messages; fabrication warning now printed after the call from `hadIntent`); command suite unchanged-and-green. |
| `backend/app/Http/Controllers/Api/DevPaymentSimulationController.php` | new | `abort_unless(! production, 404)`; resolves invoice from body `invoice_id` (404 unknown); ownership 403 / payable 409; `simulate($invoice, 'google_pay_card')` → 200 ids; domain → 422; unexpected → `report()` + 500. |
| `backend/routes/api.php` | modified | `POST /dev/payments/simulate` in the `auth:sanctum` group, `throttle:20,1,payments-simulate`. |
| `backend/tests/Feature/DevPaymentSimulationControllerTest.php` | new | 200 paid + ids + source + email pushed (Queue::fake); 403 unlinked; 409 already-paid; 404 unknown invoice; 404 in production (`putenv APP_ENV` + `refreshApplication`, restored in teardown-of-test). |
| `backend/tests/Feature/PaymentSimulationServiceTest.php` | new | Intent fabrication + reuse, source recording, non-payable throw. |
| `frontend/src/lib/api.ts` | modified | `simulatePayment(invoiceId)` → `POST /api/dev/payments/simulate`. |
| `frontend/src/components/portal/google-pay-button.tsx` | modified | `onSimulate` prop; "Simulate payment (test)" link rendered only when `isTestKey()` AND `onSimulate` (unavailable + main returns; disabled early-return link-free). |
| `frontend/src/components/portal/payment-method.tsx` | modified | `startSimulateGooglePay` (fire-and-poll: `simulatePayment` → `setTestPaymentPending(true)`, no qr-phase touch); `onSimulate` passed to the button. |
| Tests | modified | `google-pay-button.test.tsx` +5 (simulate link: unavailable/ready present + click, live-key absent, no-prop absent, disabled absent); `payment-method.test.tsx` +1 (simulate fast-poll → success modal at 2s). |
| Docs | modified | ARCHITECTURE.md webhook-simulation bullet, implementation-notes Payments §8 (harness paragraph), product-decisions §55, manual-tests Google Pay step 5 (in-page Simulate first). |

**Plan deviation (flagged):** the blueprint's controller signature `store(Request,
Invoice $invoice, …)` assumed route-model binding, but its own route is
`POST /dev/payments/simulate` (no `{invoice}` path param) and the frontend/tests post
`invoice_id` in the body. Resolved by resolving the invoice from the body (`find` → 404
when unknown) instead of binding. The 404-unknown-invoice case is covered by a test.

**Re-validated:** backend 48/48 (PayMongoService + command + controller + service suites),
frontend `npm test` 238/238 (23 files), lint 12 problems (6 errors all pre-existing; +1
warning mirrors the existing `startCard` missing-dep pattern), `npm run build` clean.

## Addendum 2 — simulate intent-freshness fix (reported after manual QR Ph round)

**Report:** "the simulate payment is so wrong — this is the webhook from PayMongo" with a
real `qrph.expired` delivery pasted. The user tested the QR Ph real round (created a real
QR Ph code at ~19:01, PayMongo fired `qrph.expired` for its intent `pi_fUSWQrwDi8dT4kMmA1xt9QEa`
at 19:11), then used the Google Pay simulate on the same invoice. The simulate had reused
that stored QR Ph intent id in its fabricated `payment.paid`, so the simulated Google Pay
payment was attributed to the QR Ph intent — looking like the simulation "triggered a QR Ph".

**Diagnosis (docs-verified):** the `qrph.expired` delivery is a genuine PayMongo event for
the user's own QR Ph code; the simulate makes no PayMongo API calls and cannot fire real
webhooks. Per `payment-acceptance-google-pay.md` + `developer-tools-webhooks-events.md`,
Google Pay runs the standard Payment Intent lifecycle and produces the general
`payment.paid` event (source `google_pay_card`) — exactly what the simulate dispatches. The
defect was intent reuse + an under-faithful payload.

**Fix:** `PaymentSimulationService::simulate()` gains `forceFreshIntent` (default false —
CLI reuse behavior unchanged); `DevPaymentSimulationController` passes `true`, so the
harness always fabricates a fresh `pi_sim_` intent and can never reuse a leftover stored
intent from another flow. The fabricated `payment.paid` payload is now a faithful real
delivery envelope (event `created_at`/`updated_at`/`previous_data` + full payment resource
with `balance_transaction_id`, `fee`/`net_amount`, `origin: api`, `statement_descriptor`,
`available_at`, `created_at`/`paid_at`/`updated_at`, `source: {type: google_pay_card,
brand, last4, country}`). New tests: service `test_force_fresh_intent_never_reuses_a_stored_intent`;
controller 200-case now seeds a leftover real intent and asserts the stored intent is
replaced with `pi_sim_`. Docs updated (implementation-notes §8, manual-tests gotcha).

**Re-validated:** backend simulate suites 23/23 (PayMongoServiceTest unaffected),
frontend untouched (238/238 from the prior run).