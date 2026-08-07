# 2026-08-07 — Payment flow Screen 1 (Payment Method) + portal responsive pass

## Goal

ARCHITECTURE.md Customer Portal item "Payment flow Screen 1 — Payment Method": three
tappable cards (E-wallet live, Card + Digital Wallet disabled "Coming soon"), QR Ph
rendered in-page from `next_action.code.image_url` with a countdown driven by the
**backend** `expiry_seconds` (never a frontend constant), GCash redirect with `return_url`,
plus the portal-shell responsive upgrade (tablet → desktop) and the new AGENTS.md
responsive rule (Rule 10).

## Files created or modified

Backend (small — the attach is client-side per PayMongo docs):
- `config/services.php` — `paymongo.qr_expiry_seconds` (env `PAYMONGO_QR_EXPIRY_SECONDS`, default 600; docs range 60–9000)
- `app/Http/Controllers/Api/InvoicePaymentController.php` — `/pay` response now includes `expiry_seconds`
- `backend/.env.example` — new var documented
- `tests/Feature/InvoicePaymentEndpointTest.php` — +1 test (`expiry_seconds` comes from config)

Frontend:
- `lib/paymongo.ts` (NEW) — client-side PayMongo calls with the public key
  (`NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY`): `createPaymentMethod(type, extra)` +
  `attachPaymentMethod({intentId, clientKey, paymentMethodId, returnUrl?})`; `data:image/`
  prefix guard on the QR image URL; PayMongo error payloads mapped to friendly messages.
- `lib/countdown.ts` (NEW) + `hooks/use-countdown.ts` (NEW) — mm:ss formatting,
  timestamp-based countdown (interval + focus/visibility recompute, tab-throttle safe).
- `lib/api.ts` — `startPayment(invoiceId)` (validates `client_key`/`payment_intent_id`/
  `expiry_seconds`), `buildReturnUrl()`.
- `components/portal/payment-method.tsx` (NEW) — Screen 1 (details below).
- `app/dashboard/pay/page.tsx` (NEW) — query route `/dashboard/pay?id={id}`, auth guard,
  GCash `return_url` target. (Started as `[invoiceId]` param route — see bugs.)
- `components/portal/bill-card.tsx` — "Pay now" button (optional `onPay` prop).
- `components/portal/bills-list.tsx` — Pay button → `/dashboard/pay?id={id}`.
- `app/dashboard/page.tsx` — container `max-w-md md:max-w-4xl lg:max-w-5xl`.
- `components/portal/bills-list.tsx` — bills grid `sm:grid-cols-2 lg:grid-cols-3`.
- `.env.example` — `NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY` documented.

Docs: `AGENTS.md` Rule 10 (mobile-first, verified tablet ≥768px + desktop ≥1024px,
viewport pass in every frontend session's manual checks), `ARCHITECTURE.md` (Screen 1
checked + responsive note), `docs/insights/product-decisions.md` §35 (QR Ph countdown
source of truth), this summary.

## How Screen 1 works

- Tap "Pay now" on a bill → `/dashboard/pay?id={id}` → invoice must still be in the
  user's unpaid list (else "not available").
- E-wallet card (Recommended badge): two rows — **Scan QR · QR Ph** (default) and
  **Open in GCash**.
- QR Ph: `startPayment` → backend returns `client_key` + `expiry_seconds` (config) →
  browser creates the QR Ph payment method with that exact `expiry_seconds` (public key,
  never through Laravel) → attaches with `client_key` → renders
  `next_action.code.image_url` (Base64) → countdown = attach time + backend
  `expiry_seconds`, persisted in `sessionStorage` keyed by intent id (refresh-safe;
  resumed only while valid, else fresh attach). Expired → "QR expired" + "Get a new QR".
- GCash: attach with `return_url` (required by PayMongo) →
  `window.location.assign(next_action.redirect.url)`; return lands on the screen with a
  pending banner (marker `?from=gcash` consumed once via `history.replaceState`).
- Payment detection: 15s poll of `GET /api/invoices` while open (≈4 req/min, well under
  `throttle:30,1`); invoice gone → "Payment received" panel.
- E-wallet disabled when `total_amount > ₱100,000` (cap); Card + Digital Wallet render
  disabled "Coming soon".

## Bugs found and fixed

1. **Static-export dynamic route (build failure).** `/dashboard/pay/[invoiceId]` failed
   `next build` ("missing generateStaticParams" with `output: export`). Root cause:
   `next.config.ts` is full static export — param routes only exist for params known at
   build time. Fix: query route `/dashboard/pay?id={id}` (equally good as a `return_url`
   target, survives refresh).
2. **`Remove-Item` silently didn't delete the `[invoiceId]` folder.** PowerShell treats
   `[invoiceId]` as a wildcard character class, so the plain path delete matched nothing.
   The stale folder kept breaking the build until `-LiteralPath` was used. (Tooling note,
   not app code.)
3. **TL `findBy*` hung under vitest fake timers** (every async test timed out at 5s).
   Root cause: `globals: false` in vitest config means `@testing-library/dom`'s waitFor
   can't detect vitest fake timers, so it polled with the *faked* `setTimeout` that never
   fires. Fix: use `vi.waitFor` (vitest's own — auto-advances fake timers) and enable
   `vi.useFakeTimers()` only in the time-dependent tests.
4. **401 check was `instanceof ApiError`** — the API layer throws plain `Error`s with a
   `status` property from `resolveResponse`, so the redirect never fired. Fix:
   property-based `isUnauthorized()`.
5. **`publicKey()` config error swallowed** — it threw inside the fetch try/catch, which
   rethrew the generic "Unable to reach the payment gateway" message. Fix: compute the
   auth header before the try.
6. **Lint `set-state-in-effect` (3 new)** — invoice id read, pending banner, expiry
   transition. Fixed properly: lazy `useState` initializers for the first two;
   render-time derivation (`qrExpired`) instead of an effect-driven phase flip for the
   third. Remaining warnings: pre-existing suite errors untouched; one `<img>` warning
   (deliberate — Base64 data URI, next/image can't optimize it).

## Verification

- Frontend: **46/46 tests** (`npm test`, 7 files: payment-method 15, pay page 4,
  bills-list 6, paymongo 6, api 7, countdown 2, dashboard guard 3, formatPeso 3).
  `next build` passes — `/dashboard/pay` generated static. Lint: no new errors.
- Backend: **487/487 passed, 2,201 assertions** (InvoicePaymentEndpointTest 11/11 incl.
  the new expiry_seconds test).
- NOT yet verified manually (needs the browser + real test-mode PayMongo):
  CORS from `localhost:3000` on the PayMongo public-key calls, live QR render +
  countdown, GCash redirect round trip, `paymongo:simulate-payment` → paid panel,
  viewport pass at 390/768/1280px.

## Known gaps / next step

- `.env.local` (frontend) still needs `NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY=pk_test_…` — copy
  from `backend/.env` `PAYMONGO_PUBLIC_KEY`; restart `npm run dev` after.
- Manual verification checklist is in the response — run it before committing.
- Next unchecked checklist item: **Screen 2 — Review & Pay** (pending/success states live
  there; Screen 1 ships a minimal pending banner + paid panel).

## Addendum 3 — GCash test round trip: webhook deliveries stuck "processing" + paid-vs-not-payable confusion (fixed same evening)

**Symptom:** the GCash test (Fail → back → Authorize) paid invoice 11 on PayMongo's side,
but the return page said "This bill isn't available for payment right now.", and retries
showed "A payment for this invoice already went through…". The dashboard listed
`qrph.expired` ×2, `payment.failed`, `payment.paid` — all stuck at "processing".

**Root cause:** the webhook controller received NOTHING all day (`paymongo.log` — the
last "webhook received" was 08-06). The dashboard webhook URL points at a STALE ngrok
tunnel (ngrok free = new URL every restart; today's is `33b2-…ngrok-free.app`, verified
via the ngrok local API + a tunnel probe that reached the controller and got the expected
401 on an unsigned body). PayMongo keeps retrying the dead URL → "processing". Invoice 11
is `overdue` locally with zero Payment rows — the `/pay` 409 guard is correct and blocked
a double charge.

**Fix (code + infra):**
- Code: `qrph.expired` added to `KNOWN_EVENT_TYPES` (whitelist + job, log-only) — per the
  user's dashboard change; `GET /api/invoices/{id}` (`InvoiceController@show`,
  ownership-gated, any status incl. paid) + pay-screen fallback: when the invoice is no
  longer in the unpaid list, the screen checks its status — `paid` → "Payment received"
  panel (kills the confusing "This bill isn't available" right after a successful GCash
  payment), otherwise → not-available. Tests: webhook qrph.expired dispatch, show 401/403/
  paid/unpaid, frontend paid-fallback + 401 + not-payable. Suites: backend 492/492,
  frontend 54/54, build static, lint clean.
- Infra (user): update the PayMongo dashboard webhook URL to the CURRENT tunnel
  (`https://33b2-209-35-174-196.ngrok-free.app/api/paymongo/webhook`), then Resend the
  `payment.paid` delivery → invoice 11 credited, receipt email queued (worker running).
  E2E doc gotcha updated.

## Addendum 4 — Dashboard: connection dividers + Past payments drawer (same evening, user feedback)

**Ask:** after paying, the bill just vanished ("payment succeeded looks like a separate
page") — paid info should be hidden in a "past payments" drawer, not disappear. Also with
multiple linked meters the bills list gets cluttered — needs category/divider per
connection.

**Built:**
- Backend: `GET /api/payments` — `Api\PaymentController@index` +
  `PortalBillsService::recentPayments()` (last 10, `paid_at` desc, active links only;
  returns invoice no/period, amount, method + channel, paid_at, connection).
  `PaymentListingApiTest` 7 tests (401, ordering, stranger/revoked exclusion, empty,
  limit 10, channel).
- Frontend: bills list grouped under per-connection dividers (account · meter /
  registered name · barangay), order preserved within each group; `PastPayments`
  component — collapsible drawer, fetches only on first expand, rows (invoice · account ·
  paid date / amount + method chip GCash·QR Ph·Card·Cash-office·Online), empty/error/401
  states. Test suites: backend 499/499, frontend 63/63, build static, lint clean.
- Note: paid bills don't need a DB change — they naturally surface via the payments
  endpoint; the drawer is the paid-bill home.
- **Label fix (user feedback):** the drawer showed "Online" for old online rows with no
  channel (`paymongo_source` added later, no backfill — invoice 1's Jul payment). Now
  mirrors the admin's §23 convention: channel-less `paymongo` rows → "PayMongo"; unknown
  methods prettified from the raw key ("bank_transfer" → "Bank Transfer").

## Addendum 5 — Screen 2 (Review & Pay) + ARCHITECTURE.md slim-down (same evening)

**Screen 2 built** (per the checklist + `payments-customer-portal-flow.md` spec): the pay
flow is now two steps on `/dashboard/pay?id=X` — method rows are **selectors** (zero API
calls until Pay), the review step shows line items (Current charges / Arrears / Penalty
only when nonzero / Total — matches the PDF breakdown), the selected method with a
**Change** link back (selection + QR state preserved), and a **[Pay]** button that fires
the existing QR Ph attach flow or the GCash redirect. Pending until the webhook confirms —
never marked paid on redirect (existing 15s poll + invoice-status fallback flip the paid
panel). No backend changes. Tests: payment-method suite reworked to the 2-step flow (+4
new: no-auto-attach regression, line items/hidden zero rows, Change with no calls,
double-Pay single-attach) — frontend 67/67.

**ARCHITECTURE.md slim-down:** the Implementation Status checklist's long implementation
paragraphs moved **verbatim** to the new `docs/insights/implementation-notes.md` (same
sections, `§N` numbering — Payments §1–7, Customer Portal §1–3, Admin Panel, Admin
Reports, Customer Registration, Notifications + ops notes, Infra/Ops §1–4, Auth, Meter
Readings, Billing). ARCHITECTURE.md bullets are one-liners with `(details: … → §…)`
pointers; the section intro now states it's the index. **No-loss verified by script**
(35 content sentinels all present in the notes; 286 → 231 lines). AGENTS.md Rule 8 gained
the implementation-notes.md entry (new detail archive; never long paragraphs in the
checklist again).

## Git state

NOT committed (per project rules). HEAD before this session: `c97b919`.

## Addendum — "This bill isn't available" on first click (fixed same evening)

**Symptom:** clicking Pay now → `router.push("/dashboard/pay?id=11")` landed on the
"not available" panel; a hard refresh showed the payment screen correctly.

**Root cause:** `pay/page.tsx` read the invoice id from `window.location.search` in a
lazy `useState` initializer (one-shot, at mount). During a client-side transition the
new page's first render can run **before the router commits the URL**, so it read `""`
and locked it forever → `getInvoices()` couldn't find the invoice → not-payable. Hard
refresh re-ran with the committed URL → worked.

**Fix:** replaced the window read with `useSearchParams()` inside a `<Suspense>` boundary
(the canonical, router-state-reactive API — verified against Next 16.3 docs; required
for the static-export build). `from=gcash` moved to the same hook and passed as a
`returnedFromGcash` prop to `PaymentMethodScreen`, deleting the second one-shot
`window.location` read (the banner). Test count 46 → 49 (new regression tests: id from
router params, gcash-return flag, no-banner-on-normal-visit). `npm test` 49/49, `npm run
build` passes (`/dashboard/pay` stays static), lint: no new errors.

## Addendum 2 — "We couldn't generate a QR code" (real API shape mismatch, fixed same evening)

**Symptom:** after the env fix, tapping Scan QR failed with "We couldn't generate a QR
code. Please try again." — while the pay screen, CORS, and `/pay` all worked.

**Root cause (found via a live test-mode repro script, not the docs):** the attach
response's `next_action.type` is **`"consume_qr"`**, but `lib/paymongo.ts` only accepted
`"code"` — so the valid QR was parsed as null and the error panel showed. The docs
describe the `next_action.code` object (image_url, expires_at) but never document the
`type` enum value; the OpenAPI reference schemas are empty (`"value": "{}"`). Also
learned: the response carries PayMongo's authoritative `next_action.code.expires_at`
(RFC3339).

**Fix:** `attachPaymentMethod` accepts `consume_qr` (+ `"code"` as a defensive alias) and
extracts `expiresAt`; `payment-method.tsx` uses `Date.parse(expiresAt)` as the countdown
deadline (fallback: attach time + backend `expiry_seconds`) — the countdown is now
literally driven by PayMongo's expiry moment. Tests: `paymongo.test.ts` (consume_qr
shape + expiresAt extraction + legacy alias), `payment-method.test.tsx` (expiresAt in
attach mocks; exact-seconds assertions made deterministic via
`mockImplementation(() => Promise.resolve(qrAttachResult()))` — the mock computes
`expires_at` at attach-invocation time so `vi.waitFor`'s fake-clock advancement in
`renderReady` can't skew it — and `flushAsync()` instead of `waitFor` inside the
countdown tests). 50/50 tests, build static, lint clean. product-decisions §35 got the
correction note.
