# Implementation Notes — detail archive for ARCHITECTURE.md

> **Purpose:** ARCHITECTURE.md's Implementation Status checklist is the **index**; this file
> carries the full implementation notes that used to live inside the checked bullets. Items
> are grouped by the same sections and ordered the same way; dates and product-decisions
> pointers are preserved verbatim. When a checklist item is completed, its note lands here —
> not in ARCHITECTURE.md.
>
> Created 2026-08-07 during the ARCHITECTURE.md slim-down (the checklist had grown long
> implementation paragraphs). Companion archives: `docs/insights/checklist-archive.md`
> (pre-trim sub-bullet text) and `docs/insights/product-decisions.md` (the "why").

## Auth

### 1. Login failure handling per site
Filament admin differentiates wrong credentials vs valid-but-not-admin ("This account does
not have access to the admin panel." — custom `App\Filament\Auth\Login`); customer
`/api/login` returns one generic message (no email enumeration) + `throttle:10,1`; frontend
shows a friendly error when the server is unreachable.

### 2. API unauthenticated responses
`/api/login` route is named `login` so `auth:sanctum` failures on API routes never crash
with `RouteNotFoundException`. With `Accept: application/json` the response is a clean
`401 {"message":"Unauthenticated."}`; without it, Laravel redirects to the named route
instead of 500ing. REST clients (Thunder Client) inject `Accept: */*` by default —
duplicate `Accept` headers make the first one win and produce the 500 (see README →
Testing the customer API).

## Meter Readings

### 1. Manual entry form in Filament
Auto-computes `cu_m_used`, auto-fills `previous_reading`, auto-flags `present < previous`
as level 2, minimum 30-day gap since last reading enforced, duplicate-date rejection.

### 2. CSV bulk import in Filament
Upload → preview → validate → import.

### 3. Validation on import
Per-row errors, flags suspicious readings, rejects invalid: bad rows, future dates,
<30-day gaps since the previous reading, duplicates; optional `flagged` column respected;
preview downloadable with notes for fix-and-reimport round-trip.

## Billing

### 1. Billing calculation logic
`App\Services\BillingService` (reads RateSchedule + PenaltyRule, never hardcodes rates;
flat + tiered; arrears carryover + 2%/month penalty after grace; flagged/no-reading/zero/
invalid connections skipped + reported; `billing:run` manual runs) — full rules:
`docs/insights/billing-decisions.md`.

### 2. Billing run as a queued job
`RunBillingJob` + per-run report row in `billing_runs` (`billing:run --sync` inline,
`billing:report {id}`); Postgres partial unique index blocks concurrent runs per period.

### 3. Invoice PDF generation
dompdf — itemized, matches real bill breakdown (current charges, arrears, penalty, total).

## Payments

### 1. PayMongo integration (create payment intent/checkout)
`App\Services\PayMongoService::createPaymentIntent()` POSTs `/v1/payment_intents` (Basic
auth, amount in centavos `round(total*100)`, persists `paymongo_payment_intent_id` on the
invoice (unique — one intent per invoice), `Idempotency-Key: invoice-pay-{id}`,
`payment_method_allowed` whitelisted, 15s HTTP timeout, 3× retry).
`getOrCreatePaymentIntent()` check-then-act in `DB::transaction` + `lockForUpdate` (rejects
non-`unpaid`/`overdue` with 409; self-heals stale intents — succeeded → 409 double-pay
guard, expired/unknown → fresh intent); `paymongo` log channel. Endpoint
`POST /api/invoices/{invoice}/pay` (Sanctum, `throttle:20,1`) — inactive link → 403,
returns `client_key` + intent id + `expiry_seconds` (config
`services.paymongo.qr_expiry_seconds`, default 600 — the portal countdown's source of
truth, *2026-08-07*), PayMongo failure → 502 + `report()`. Faked-HTTP tests. Env:
`PAYMONGO_SECRET_KEY`, `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_WEBHOOK_SECRET`,
`PAYMONGO_LIVEMODE`.

### 2. PayMongo webhook route (signature verified, idempotent)
`POST /api/paymongo/webhook` (no auth; controller only acks `200 {"received":true}` within
the 30s window, zero DB/HTTP work). Signature: `Paymongo-Signature` header
`t=<unix ts>,te=<test sig>,li=<live sig>`; signed string `"<t>." . $rawBody`
(`getContent()`, pre-parse); HMAC-SHA256 hex; `hash_equals` vs `te`/`li` per
`PAYMONGO_LIVEMODE`; fails closed 401 (exact format doc-verified — see AGENTS.md doc trap;
`X-Paymongo-Signature` fallback accepted). Livemode guard (event must match
`PAYMONGO_LIVEMODE`, read via `filter_var FILTER_VALIDATE_BOOLEAN`); separate test/live
endpoints. Known event types: `payment.paid`, `payment.failed`,
`payment_intent.succeeded`, `payment_intent.awaiting_payment_method`, `qrph.expired`
*(2026-08-07)* — anything else → ack + skip (never 4xx/5xx, avoids PayMongo's up-to-12×
retry).

### 3. Invoice marked paid on webhook confirmation
`App\Jobs\ProcessPayMongoWebhook` (queued, tries=3) → `PaymentService::markPaidFromWebhook()`
(business logic kept out of the job/controller): atomic `DB::transaction` +
`lockForUpdate` on the invoice, **amount guard** (event centavos must equal
`round(total*100)`), creates `Payment` row (`method=paymongo`, `paymongo_reference` =
payment id `pay_…` — unique index backstop), sets invoice `status = paid`; only ever
`{unpaid, overdue} → paid`; only `payment.paid` marks paid (other events log-only).
Dedupe: `processed_webhook_events` (unique `event_id`, written atomically with the state
change) + already-paid skip — dashboard Resends/redeliveries never double-pay.
`paymongo:reconcile` — read-only CLI safety net (Leg A: charged-but-not-credited vs stored
intents; Leg B: payments without local record; 5xx → "UNCHECKED"), runs daily on host.

### 4. Invoice PDF emailed to customer on payment confirmation
`App\Mail\PaymentConfirmation` + `App\Jobs\SendPaymentConfirmationEmail` (queued,
dispatched `afterCommit` from `markPaidFromWebhook`, ONLY when a `Payment` row was
created); in-memory PDF via `PdfService::generate()` (no permanent storage). Recipients =
all distinct valid emails (lowercased + deduped) of portal users with an `active` +
non-unlinked `ConnectionLink` (revoked excluded; none → skip, nothing lost — payment
already recorded). Failure → admin Filament DB notification with a one-click **Resend
receipt** button (`GET /admin/payments/{payment}/resend-receipt`, `auth:admin` guard,
sends synchronously like the CLI) + `paymongo:send-receipt {invoice}` CLI fallback (exit 0
= sent/skipped, 1 = unknown/unpaid; product-decisions §27). **Resend is idempotent**:
notifications carry `data.payment_id`/`invoice_id` (tagged in `failed()` by action-URL
fingerprint), and a successful resend rewrites the linked rows to a resolved state
(`resolved_at`, success color, body "Receipt resent …", button removed) — a second click
(any admin, or the raw URL) only warns, never duplicates the email; a row lock across
check+send+resolve serializes double-clicks; `throttle:10,1` backstop on the route; legacy
untagged rows still matched via the stored action URL (product-decisions §28). Mailtrap
dev / Resend prod (`MAIL_MAILER=log` fallback); test log isolated via
`PAYMONGO_LOG_DRIVER=single` + throwaway path in `phpunit.xml`. Delivery at-least-once by
design; shared-To deliberate (product-decisions §17).

### 5. Record offline/manual payments in admin
Create-only Filament `PaymentResource` (`/admin/payments`; no edit/delete on money rows)
backed by `PaymentService::recordOfflinePayment()`: atomic `DB::transaction` +
`lockForUpdate`, only `{unpaid, overdue} → paid`, **nearest-peso tolerance** (≤ ₱1.00 of
total), day-granularity future-date guard (garbage → clean `InvalidArgumentException`),
`reference` ≤ 100 chars, `recorded_by` audit (nullable FK, mirrors
`meter_readings.entered_by`), offline rows keep `paymongo_reference` NULL, warns (not
blocks) when the invoice still holds an intent. `payments:record` CLI mirrors the form
with identical guards. No receipt email on offline (product-decisions §20–21).

### 6. PayMongo channel captured + admin display fixed
`payments.paymongo_source` (string 30) = raw PayMongo channel key (`gcash`, `card`,
`qrph`, …) from the webhook's `…source.type`; **channel only**, card brand/last4 not
stored (PCI surface). `reference` and `recorded_by` stay NULL for online rows (OR/audit
semantics) with display fallback `reference ?? paymongo_reference ?? '—'` / "Processed By:
PayMongo" (admin column label "Processed By" — covers both channels and over-the-counter
entries); Method select widens `options()` with the record's actual method (native selects
won't show values absent from `options`); no backfill (product-decisions §23).

### 7. Payer identity captured + shown on receipt *(2026-08-06)*
`payments.payer_name/email/phone` (nullable 255/255/40, from webhook `attributes.billing`
— name/email/phone, normalized in the job: non-string/empty/whitespace → NULL, overlong
truncated so a bad payload can never blow the column inside the money transaction;
`billing` null/missing → NULL; no backfill). Offline rows stay NULL
(`recordOfflinePayment` untouched). Email body gains Customer (registered_name), Account
No., Meter No., Payer (name · email · phone, '—' fallback) rows — `PaymentResource` shows
a toggleable Payer column + view placeholder. The emailed PDF attachment shows the same
payer row (`PdfService::generate($invoice, $payment)`, '—' when no payment/payer).
Rationale: product-decisions §26.

## Customer Portal (Next.js)

> Detailed flow spec: `docs/prompts/payments-customer-portal-flow.md` (frontstage spec).

### 1. Portal shell: dashboard + unpaid-bills list
`GET /api/invoices` (Sanctum, `throttle:30,1,invoices-index`) via
`App\Services\PortalBillsService` (unpaid + overdue for the user's active links,
overdue-first then due date; `Api\InvoiceController@index` returns connection + period +
amounts). Next.js: `/dashboard` route (client auth guard → redirect `/auth`; `/auth`
redirects signed-in users to `/dashboard`), mobile-first portal shell (sticky header w/
user + Sign Out, "My Bills" summary, bill cards with status badges,
loading/error-retry/empty states, 401 → auto logout+redirect, no pay button by design).
Frontend test runner added: Vitest 4 + Testing Library + happy-dom (`npm test`, 13 tests
— guard, list, empty, error, retry, 401, formatPeso; presentational components covered via
list tests, not per-file suites). Responsive per AGENTS.md Rule 10: fluid container
`max-w-md md:max-w-4xl lg:max-w-5xl` + bills grid 1-col → `sm:grid-cols-2` →
`lg:grid-cols-3` *(responsive pass 2026-08-07)*. Bills grouped under a per-connection
divider (account · meter + registered name · barangay) so multi-meter users aren't looking
at a flat pile; **Past payments drawer** — `GET /api/payments` (Sanctum,
`throttle:30,1,payments-index`) via `PortalBillsService::recentPayments()` (last 10,
`paid_at` desc, active links only; `Api\PaymentController@index` returns invoice
no/period, amount, method + channel, paid_at, connection) — collapsible section that
fetches on first expand (collapsed = no fetch), rows show invoice no · account · paid
date, amount + method chip (GCash/QR Ph/Card/Cash-office/PayMongo — channel-less online
rows labelled like the admin's "Processed By: PayMongo", per product-decisions §23;
unknown methods prettified from the raw key), empty + error/retry + 401 states
*(2026-08-07)*.

### 2. Payment flow Screen 1 — Payment Method
`/dashboard/pay?id={invoiceId}` (static-export-safe query route, client auth guard; also
the GCash `return_url` target). E-wallet card (Recommended badge, default) = two
first-class rows: **Scan QR · QR Ph** — client-side flow per PayMongo docs:
`POST /api/invoices/{id}/pay` returns `client_key` + `payment_intent_id` +
**`expiry_seconds` (backend config `services.paymongo.qr_expiry_seconds`, default 600)**;
browser creates the QR Ph payment method with the **public key**
(`NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY`, never through Laravel) passing that `expiry_seconds`,
attaches with `client_key`, renders `next_action.code.image_url` (Base64 data URI,
`data:image/` prefix guard; `next_action.type` is `consume_qr` — not `"code"`, verified
against a live test-mode attach 2026-08-07). Countdown deadline = PayMongo's own
`next_action.code.expires_at` (RFC3339) with a fallback of attach time + backend
`expiry_seconds` — never a frontend constant (product-decisions §35); persisted in
`sessionStorage` (survives refresh, resumed only while the deadline is still live,
re-attach otherwise), timestamp-based recompute (tab-safe), expired → "Get a new QR"
(fresh PM+attach). **Open in GCash** — attach with `return_url` (required) →
`window.location.assign(next_action.redirect.url)`; 4-hr window, no countdown; return →
pending banner + poll. Payment detection: 15s poll of `GET /api/invoices` while the screen
is open (invoice leaves the list = webhook confirmed → "Payment received" panel). When the
invoice is already gone on load (webhook beat the UI, e.g. GCash/3DS return),
`GET /api/invoices/{id}` (`InvoiceController@show`, ownership-gated, any status)
disambiguates: `paid` → "Payment received" panel; **403/404** → "not available" (the only
definitive negatives); anything else — transient probe failures (network/429/5xx) or an
unrecognized response shape — lands on a "checking your payment status" state that keeps
polling until the webhook reports paid (a failed probe never renders the definitive
dead-end; hit 2026-08-07 on the 3DS return). E-wallet
disabled above ₱100,000 (card needed — coming soon). Card + Digital Wallet cards render
disabled "Coming soon" (card form + Google Pay capability are later items).

### 3. Payment flow Screen 2 — Review & Pay *(2026-08-07)*
Two-step flow on `/dashboard/pay?id=X` (tap row → review directly, Steam-style). Method
step: E-wallet rows are selectors — no API calls until Pay. Review step: line items
(Current charges `base_amount`, Arrears `previous_balance` and Penalty `penalty_amount`
only when > 0, divider, Total), selected method line + **(Change)** link back to the method
step (selection preserved, QR state untouched), **[Pay]** button (disabled while busy):
QR Ph → existing startQrPh (intent → PM with backend `expiry_seconds` → attach → QR +
countdown in the right column); GCash → existing startGcash (attach with `return_url` →
redirect). Pending banner (GCash return) renders on the review step; **never mark paid on
redirect — the webhook is the source of truth** (15s poll + invoice-status fallback
flip to the "Payment received" panel). Refresh mid-review re-fetches (paid → paid panel;
selection resets to the method step). No backend changes — reuses `/pay`, client-side
attach, poll, and webhook.

### 4. Card form — client-side PM creation + 3DS *(2026-08-07)*
Card card enabled on the method step (Digital Wallet keeps "Coming soon"). Selecting Card
advances to the review step, which renders the inline `CardForm` (Card Info: number
formatted 4-groups ≤19 digits, expiry MM/YY, CVC 3–4; Billing: first/last name, address,
address 2 + phone optional, city, ZIP — all required except the optional two — country
fixed "Philippines", email prefilled read-only from the session). Validation runs on Pay
press with inline `role=alert` errors (Luhn via `lib/card-utils.ts`, expiry end-of-month
vs today, CVC length) — zero API calls until clean. `startCard`: `startPayment` →
`createPaymentMethod("card", {details: {card_number, exp_month, exp_year, cvc}, billing:
{name, email, phone?, address: {line1, line2?, city, postal_code, country: "PH"}}})` with
the **public key** — card data never touches the Laravel backend, is never logged, and is
never persisted (no sessionStorage for PANs; form resets on refresh/method switch by
design) — then `attach` with `return_url`. Attach outcomes (docs-verified,
payment-acceptance-cards): `awaiting_next_action` + `next_action.redirect.url` → 3DS
redirect (`window.location.assign`); `processing`/`succeeded` → "Payment processing" note
+ the 15s poll/webhook resolve it; `awaiting_payment_method` → `last_payment_error`
surfaced (declined card) with Try again keeping the form values. Return from 3DS uses the
generic `?from=redirect` marker (legacy `?from=gcash` still accepted) → pending banner;
**never mark paid on redirect — the webhook is the source of truth** (paid panel flips via
poll/invoice-status fallback). **Redirect-return reliability (2026-08-07 hardening):**
PayMongo strips the query string on redirect returns — the return page can land with no
`id`/`from` — so before any redirect (`window.location.assign`) the flow writes the
invoice id to `sessionStorage` (`gw-pending-invoice`, written in `startGcash`/`startCard`,
consumed + cleared by the pay page when the URL has no `id`; URL id wins when present).
The unconfirmed poll probes `getInvoice` directly (independent of the list call) so a
single failed request can never wedge the retry, and an empty id renders not-payable
without any API calls. Billing object also populates the webhook's payer identity
(`payments.payer_*`). E-wallet cap > ₱100k now makes the bill **Card-only** (E-wallet card
disabled + note, Card stays enabled). Test cards: `4343…4345` no-3DS success,
`4120…0007` 3DS required, `4200…0018`/`4300…0017`/`5100…0198` declines. No backend
changes (intent already allows `card`; 3DS 2.0 default `any`).

**§4 addendum — redirect-return recovery + confirmed-payment feedback (2026-08-08):**
the sessionStorage-only recovery above was still failing in real 3DS testing: the return
landed on a **bare `/dashboard/pay`** (verified in the user's browser) with the invoice
id unrecovered → the screen rendered the definitive "This bill isn't available" panel
even though the webhook had paid the invoice. Root causes: (1) sessionStorage is
**per-tab** — the 3DS round trip can end in a new tab/context where it is empty;
(2) an empty/unidentifiable id rendered a *definitive* dead-end instead of an unknown
state; (3) the tests mocked only `?id=X&from=redirect` / empty-query returns — never
the real shapes — so the suite validated a false model of PayMongo's behavior.
Fix: (a) new backend endpoint `POST /api/payments/intent-status`
(`PaymentController@intentStatus`, auth + `throttle:30,1`) resolving
`payment_intent_id` → invoice (ownership-gated; returns `paid` / `confirmed`
[succeeded on PayMongo but webhook not yet credited, carries `invoice_id`] /
`failed` / `processing` / `unknown` — an unresolvable intent is **never** 404, only
`unknown`; 403 only for a foreign invoice; 502 on gateway failure). PayMongo's docs
("Extract the `payment_intent_id` from the query parameters", quick-start +
troubleshooting) confirm the intent id rides the return URL when params survive at
all. (b) `pay/page.tsx` parses `payment_intent_id`/`status`; resolution order:
numeric `id` → intent-status endpoint → pending marker → checking panel (never
not-payable). Pending marker now writes **both** sessionStorage and localStorage
(`{invoiceId, writtenAt}`, 1-h TTL, not cleared on read — StrictMode-remount safe;
backend ownership guards whatever it recovers). (c) `payment-method.tsx`: empty id →
checking panel (lazy-initialized); `paid`/`confirmed` → **immediate "Payment
received"** panel (confirmed shows "confirmed with your payment provider, updating
your account…" and polls `getInvoice` until the webhook credits, then flips to the
email text); `failed` → clear "Payment didn't go through" panel with Try again
(rebuilds the pay screen via the recovered invoice id); `processing`/`unknown`/5xx →
checking + poll that re-resolves the intent. Not-payable now renders only for a
definitive 403/404 on a *known* invoice id. Tests: backend
`PaymentIntentStatusApiTest` (11) + 429-stale pin in `PayMongoServiceTest`; frontend
+16 (real return shapes, localStorage new-tab recovery, TTL, intent resolution
paths). Backend hardening: `getStoredPaymentIntent` treats **only 404/410** as
stale → fresh intent; other 4xx (429/401) throw → 502 — a live-but-rate-limited
intent is never silently replaced (double-charge window). Known tradeoff: no
per-user validation on the pending marker (a different user on a shared browser
could recover an invoice they don't own → backend ownership returns 403/404 → the
safe panels; TTL caps the window at 1 h).

### 5. InfoTip — one consistent ⓘ tooltip/popover pattern *(2026-08-08)*
Shared `src/components/ui/info-tip.tsx` per `docs/insights/frontend-design.md` (the
seeded frontend-design doc): a single Radix **Popover** primitive controlled by
`open`, with the hover path gated on **`pointerType === "mouse"`** — `pointerenter`
opens after 200 ms (cancelled if the pointer leaves first), `pointerleave` closes
after a 120 ms grace, and leaving into the portalled content (relatedTarget check)
keeps it open; touch taps never fire the hover path (touch pointer events have
pointerType `"touch"`), so Radix's native trigger click toggles, and outside-tap /
`Escape` close via DismissableLayer — no matchMedia, no device-swap, no SSR/hydration
branching. Content renders `role="tooltip"` in a `bg-popover` card (max-w-60, fade/zoom
in-out) referenced from the trigger via `aria-describedby` while open; `onOpen/CloseAutoFocus`
preventDefault keeps focus on the trigger; `aria-label` defaults "More information";
empty/null content renders no trigger; timers cleared on unmount. Exposed
`openDelayMs`/`closeDelayMs` (defaults 200/120) for tests. Wired into three spots as
proof: CVC "3-digit code on the back of your card" (card form, via a `labelExtra` slot
on the field helper), Penalty rows (bill card + review step) "2% per month interest on
the unpaid balance, applied after the due date." Tests: `info-tip.test.tsx` (11:
hover-open delay + leave-before-delay never opens + trigger→content travel stays open +
touch toggle/outside/Escape + aria-describedby wiring + ReactNode + empty content +
unmount timer cleanup). **Testing constraint learned:** vitest fake timers hang with
React 19 + happy-dom (happy-dom has no `MessageChannel` → React scheduler falls back to
faked `setTimeout` → `act` never flushes); InfoTip tests use real timers + small delays.

### 6. Portal self-registration + profile onboarding *(2026-08-08)*
Signup is **email + password only** — the old stub ("Contact admin to create an account")
is gone. `POST /api/register` (`guest` + `throttle:10,1,auth-register`): `RegisterRequest`
validates `email` (required|email|max:255|unique) + `password`
(required|string|confirmed|`Password::min(8)`); creates the user with `name = null`
(migration `2026_08_08_000003_add_avatar_id_to_users_table` made `users.name` nullable and
added `avatar_id` nullable tinyint 1–4) and returns `{token, user}` — **auto-login**, same
shape as `/login` (both hand-build the user payload: `id, name, email, avatar_id`). The
frontend sends `password_confirmation = password` implicitly. `AuthPage` signup face now
collects only email + password (name and confirm-password fields removed); signup calls
`AuthProvider.signup()` → stores the returned session like login.

**Redirect by completeness:** `/auth`'s post-auth effect routes `avatar_id` null →
`/onboarding`, else `/dashboard`. Onboarding resume is client-side state, not a server
flag: on mount it fetches `/user` (already authed) + `GET /api/links` and picks the step —
no avatar → profile step; avatar set, no links → link step; both → all-set step
(redirect-to-dashboard there is a manual button; the page itself doesn't auto-redirect).

**`PATCH /api/profile`** (`auth:sanctum`, `throttle:30,1,profile-update`) via
`Api\ProfileController@update`: `name` required 3–20 chars (mirrors the picker's
username rules; the username IS `users.name` — decision in product-decisions), `avatar_id`
required int 1–4; returns the updated user. `AuthProvider.updateProfile()` refreshes both
the in-memory user and the localStorage session copy.

**The `/onboarding` wizard** (`frontend/src/app/onboarding/page.tsx`): step rail =
`@blocks-so/onboarding-06` adapted into a parameterized `OnboardingSteps` (done / in
progress / open dots, lucide `Check`, title + description; the demo's activity timestamps
dropped). Step 1 = `@kokonutui/avatar-picker` `ProfileSetup` as shipped (4 inline avatars,
animated color ring, username 3–20 with live counter, disabled Get Started until valid);
`onComplete` → `updateProfile(username, avatarId)`. Step 2 = Link meter form (account +
meter number, max 20 each, mirroring `LinkConnectionRequest`) → `POST /api/links`; error
mapping: `ApiError` 404 → "We couldn't find an active connection with that account and
meter number.", 409 → "This meter is already linked to another account.", other → raw
message; **Skip link** ("I'll do this later") is a first-class exit to the all-set step.
Step 3 = all-set panel (`You're all set` + Go to My Dashboard → `/dashboard`; if skipped,
the copy points to the dashboard prompt). Layout: mobile-first single column with the
rail above the card; `lg:grid-cols-[260px_1fr]` puts the rail in a left column (the rail
is rendered twice, CSS-hidden on mobile — responsive duplicate). No auto-redirect off the
page when the profile is complete (the button does it).

**One-active-link guard (security hardening):** `ConnectionLinkController::store` no
longer lets anyone link any active connection. Inside a `DB::transaction` +
`pg_advisory_xact_lock($connection->id)` (precedent: the CSV import service): if an
`active` link for this connection belongs to a different user → `abort(409)` (rolls back,
JSON message above); the user's own re-link stays idempotent (`updateOrCreate`); a
`revoked` link frees the connection for anyone. The 404 path changed from `firstOrFail`
(ugly "No query results…" message) to an explicit check returning the friendly message.

**Dashboard prompt:** `LinkMeterPrompt` (portal header area) fetches `GET /api/links`
once; ≥1 active link → renders nothing; else a dashed "Link your meter" card with a
button to `/onboarding` (resumes at the link step). 401 → quietly renders nothing
(session guard handles the flow). After a link is created anywhere, a remount of the
prompt (page navigation) reflects it.

**Deferred:** email verification (`email_verified_at` still unused); a unique `username`
column (name stays a display name); multi-connection management UI beyond first-link
onboarding (adding more links reuses the dashboard prompt → onboarding link step);
PayMongo Customer provisioning at signup (customer is created lazily on first card
attempt, unchanged). Tests: backend `RegisterApiTest` (6: success + auto-login token
usable, duplicate 422 w/ message, short password, confirm mismatch, invalid email, rate
limit 429), `ProfileUpdateApiTest` (5: success, name <3 / >20, avatar 5, 401),
`ConnectionLinkApiTest` +4 (409 other-user guard, own re-link idempotent single row,
revoked link reusable, plus the existing 404 paths now assert the friendly message shape);
frontend `app/onboarding/page.test.tsx` (11: guards, fresh-account start, profile→link
transition, link success→all-set, 404/409 messages, skip, dashboard navigation, resume at
link step, resume all-set), `components/auth.test.tsx` (4: email+password only, signup
call, error surface, login untouched), `link-meter-prompt.test.tsx` (4: loading / prompt /
hidden-with-links / navigation), `api.test.ts` +9 (register, profile, createLink,
getLinks incl. error mapping). Note: the wizard tests seed a localStorage token — without
it `authFetch` 401s and the flow stalls on the avatar step (caught during the first test
run).

## Admin Panel (Filament)

### 1. Dashboard with key metrics
`App\Services\DashboardMetricsService` (aggregate queries; revenue upper-bounded at
`now()->endOfMonth()`) + widgets `MetricsOverview` (5 stat cards) / `RevenueChart`
(6 months by `Payment.paid_at`). Metrics: customers = **active service connections**;
revenue = **all `Payment.amount`** (PayMongo + offline) by `paid_at`, never `created_at`;
outstanding = unpaid + overdue totals (definitions: product-decisions §22).

### 2. CRM views
`ServiceConnectionResource` (list + view + edit, no create/delete; identifier edits email
linked portal users via `ServiceConnectionService`); dashboard stat cards deep-link to
filtered views (customers, revenue). *[Restored 2026-08-06 — accidentally dropped in
commit b45ee74 when item 1 was marked done; completed same day.]*

### 3. Connection Links visibility
`ConnectionLinksRelationManager` on the CRM detail page: portal user (name/email), link
status (active/revoked badge), linked_at, unlinked_at; read-only, no create/edit/delete on
the money-adjacent join table *(2026-08-06, added alongside payer identity)*.

### 4. Billing management views
`InvoiceResource` (list by status, view detail/breakdown, mark paid) + "Run billing" page
from `billing_runs` run reports (Phase 3 of `docs/prompts/billing-queue-pdf-admin.md`):
`BillingRunResource` (run history: period, status badge, billed count, error; view page =
run summary + per-connection report table from the stored JSON; no create/edit/delete)
with a **Run Billing** modal on the list page (period picker defaulting to last month's
end + Force toggle that abandons a stale `running` run using the exact `forced failed`
marker `RunBillingJob` refuses to resurrect); orchestration in
`App\Services\BillingRunService` (mirrors `billing:run` semantics incl. the
concurrent-create race catch), dispatched as `RunBillingJob` *(2026-08-06)*.

## Admin Reports / Exports

### 1. Payments CSV export
`App\Exports\PaymentsExport` (maatwebsite/excel CSVs, already installed for the
meter-reading import; CSV only). Header "Export CSV" action on `PaymentResource`, respects
the active filters (method, invoice status, paid_at range); columns: paid_at, invoice no,
account no, meter no, customer name, amount, method/channel, reference, payer name/email,
recorded by. Rationale: product-decisions §26. *(2026-08-06)*

### 2. Service connections CSV export
`App\Exports\ServiceConnectionsExport` (customer master list, all registration fields),
respects status/barangay filters; same button pattern *(2026-08-07)*.

### 3. Invoices CSV export
`App\Exports\InvoicesExport` (same pattern), respects status/due_date filters; columns:
invoice no, account no, meter no, customer name, status, billing period start/end, due
date, previous balance, base, penalty, total, rate schedule, cu.m. used, reading entered
at, formula-injection sanitized *(2026-08-07)*.

## Customer Registration (Admin)

### 1. Applicant fields on `service_connections`
`phone`, `email`, `gender`, `birthdate`, `civil_status`, `occupation` (all nullable so
existing rows keep working; gender/civil_status are constrained selects — male/female,
single/married/widowed/separated); `status` gains `pending` (application → active;
`pending` excluded from billing, readings, dashboard active-count by the existing
`'active'`-only guards); CRM edit form + factory updated *(2026-08-07)*.

### 2. Create-new-connection flow in CRM
`ServiceConnectionResource` create enabled; account/meter numbers auto-suggested
(`GW-#####`/`MTR-#####`, max numeric suffix + 1 via
`ServiceConnectionService::nextIdentifier()`, office-issued formats skipped) and editable
so the office can type the real issued numbers; shared generator is the base for the
CSV-import item's blank-identifier backstops. Collision retry: each save runs in its own
SAVEPOINT (a `23505` otherwise aborts Filament's outer transaction → `25P02` and the retry
can never run); only the colliding column is regenerated (parsed from Postgres
`DETAIL:  Key (…)`), hand-typed identifiers are preserved and surface as a form error
after 3 attempts. `rate_schedules` cleanup migration (2026_08_07_100000) collapsed
duplicate seeded rows and removed incomplete legacy test connections/schedules — fixing
two identical "Standard Flat Rate" options in the Rate Schedule dropdown; dropdown labels
now show `name · effective_from` *(2026-08-07)*.

### 3. CSV import to onboard existing registrants
`ImportServiceConnections` (`/admin/service-connections/import`, ImportMeterReadings-style
upload → preview → validate → import): required columns `name`/`barangay`/`address`;
optional account_number, meter_number, phone, email, gender, birthdate, civil_status,
occupation, status, connection_date, rate_schedule; unknown columns ignored (export
round-trips). Blank account/meter numbers auto-generated (`GW-#####`/`MTR-#####`) with
in-file reservations (two blanks never collide; provided values skipped) + DB
unique-constraint roll-forward; provided duplicates (in-file or DB) → invalid row.
Barangay matched case-insensitively by name, rate_schedule strictly by name
(unknown/ambiguous → invalid row); gender/civil_status/status enum-checked; dates
validated (future birthdate rejected, connection_date defaults to today when blank);
status defaults to active. Import shares the SAVEPOINT-per-save collided-identifier retry
via `ServiceConnectionService::createWithIdentifierBackstops()` (extracted from the create
page — both paths now use one implementation; hand-typed identifiers never overwritten).
Preview shows auto-generated badges + notes, downloadable CSV-with-notes for
fix-and-reimport. Rationale: product-decisions §26. *(2026-08-07)* **Audit hardening
(2026-08-07):** roll-forward is now provenance-gated — only identifiers the
import/`prepareImportRows()` actually auto-generated (or the create form auto-suggested)
are regenerated; a provided/typed value that collides with a concurrent insert surfaces a
validation error instead of being silently renumbered. Per-row failures are logged (row,
identifiers, reason), reported in the notification, and an `imported_by` FK now records
the importer. The download-with-notes file is formula-injection sanitized; exporter
apostrophes round-trip back to clean values; `pg_advisory_xact_lock` serializes
simultaneous imports; identifier generation caches `max+1` per column and barangay/rate
lookups are preloaded (no per-row table scan); dates parse strictly (rejects `2026-02-30`,
relative strings), numeric cells never render scientific notation, and in-file duplicate
messages name the generating row.

## Notifications

### 1. Email sending working
Mailtrap in dev, Resend in prod.

### 2. Failed receipt visibility
Admin DB notification on confirmation-email failure (+ error logged to `paymongo`) —
2026-08-05.

### 3. `php artisan paymongo:send-receipt {invoice}`
Resends a receipt to all linked users — 2026-08-05.

### 4. Notification hub UI
`AdminDatabaseNotifications` (custom bell: dismiss/clear = mark-read, never deletes) +
`NotificationHub` page (`/admin/notifications`: full history, read/unread/resolved/
action-needed filters, per-row mark read/unread, mark-all-read, no delete — history is the
audit trail; resolution state `data.resolved_at` written by `ResendReceiptController` is
the only way a row stops needing action) — 2026-08-07.

### 5. Host-agnostic notification tagging/resolution
Failure notifications store the resend action as a **relative route path** (no host);
tagging and the resend controller match by `data.payment_id` first with a URL
**path-suffix** fallback, so rows created under any APP_URL/XAMPP host are found and
resolved; resolution also stamps `payment_id`/`invoice_id` so already-detection survives
action-wipe; hub action links are rebuilt from `payment_id` for the current host (never
renders a stored foreign-host URL) — 2026-08-07.

### 6. Bell unread badge restored + hub nav badge
The custom bell must extend the panel-aware `Filament\Livewire\DatabaseNotifications`
(base returns a null trigger → invisible bell, regression hit 2026-08-07); the Hub's
sidebar item shows an unread-count badge (`getNavigationBadge`) alongside the topbar bell
— 2026-08-07. **Regression fix (2026-08-07):** the `notificationClosed` listener's
parameter MUST stay named `$id` — the browser forwards the window CustomEvent detail
`{id: …}` as a NAMED argument, and Livewire container-autowires on a name mismatch
(a required `array|string $payload` param then threw `BindingResolutionException` on any
toast close / bell X click, e.g. right after "Run Billing"). The parameter name is now
pinned by a reflection regression test; the array branch remains for Livewire's test
dispatcher (positional form).

### Ops notes (Notifications)
- Worker-generated notification URLs have no request host and fall back to `APP_URL` —
  resolved 2026-08-07: action URLs are now stored host-independent (relative path), and
  the resend controller matches by `payment_id` + path suffix, so the old dev gotcha
  (stored `http://localhost` URL vs `APP_URL=http://127.0.0.1:8000`) no longer orphans
  rows. One stuck row from the old behavior was purged from dev data (id `f71b6b03-…`,
  2026-08-07).
- Notifications created before the `data.payment_id` tag (earlier dev data) are still
  found and resolved by the stored action-URL path-suffix fallback in
  `ResendReceiptController`, regardless of the host embedded in the stored URL.

## Infra / Ops

### 1. Graphify graph rebuilt vendor-free
`.graphifyignore` added: `backend/vendor/`, `backend/public/js/`; graph pruned 72,935 →
2,135 nodes, 2026-08-01.

### 2. Queue worker
Dev = **manual terminal only** (`php artisan queue:work --tries=3` / `--once`); the
auto-start Windows Scheduled Task was **removed 2026-08-07** (pegged dev disk at 100% at
boot — no auto-start on dev). Host: `deploy/linux/supervisor-gw-worker.conf` (same flags,
8h `--max-time` self-restart, rotating stdout log 10MB × 5) + `deploy/linux/cron-gw-system`
+ `deploy/linux/backup.sh` + `docs/deployment-runbook.md` — applied when a server is chosen
(Infra). All 4 jobs declare `tries=3`; scheduler wiring (monthly billing 1st 03:05 PH,
daily reconcile 06:00 PH) in `routes/console.php`. Tests: `QueueWorkerTest` (real DB-queue
→ `queue:work`), `ScheduleTest` *(2026-08-07)*.

### 3. Automatic daily DB backups enabled on host
`deploy/linux/backup.sh` (rotating `pg_dump -Fc`, keep 15; `flock`-serialized; rotates
before dumping so retention holds even on a failed run; verifies each dump with
`pg_restore -l`) + the host cron line in `deploy/linux/cron-gw-system` (`30 2 * * *` PH,
before the 03:05 billing run; sources a root-only `/etc/gw-backup.env`, dev-only
credential defaults otherwise). **Restore drill executed 2026-08-07 against the local
Postgres 18** — dump→`pg_restore -l`→scratch-DB restore (15 connections, 9
invoices)→drop, all green. Live install = Infra-phase step on the chosen host per
`docs/deployment-runbook.md` §5 (dedicated `gw_backup` role); the mandatory drill is the
runnable `deploy/linux/restore-drill.sh`. Covered by `tests/Feature/HostBackupTest`
*(2026-08-07)*.

### 4. Basic rate limiting on public API routes
Every `/api/*` route has a throttle with a **distinct per-route prefix**
(`throttle:$max,$decay,$prefix`): webhook `60/min per IP`, login `10/min` (unchanged),
`logout`/`user`/links (`index`/`store`/`delete`) `30/min per route per user`, invoice pay
`20/min` (unchanged). The prefix is required because anonymous keys derive from the client
IP alone — without it `/login` and `/paymongo/webhook` shared ONE per-IP bucket, so a
burst on one 429'd the other (a junk webhook flood could lock an IP out of login;
signature is validated *after* the throttle). Authenticated keys are user-id-only (IP
rotation cannot reset a bucket) and per-route (no cross-route lockout — e.g. `/user`
polling cannot exhaust the `/pay` budget). 429s render as JSON on `/api/*` (existing
`shouldRenderJsonWhen`) with `X-RateLimit-*`/`Retry-After`/`X-RateLimit-Reset` headers.
Tests: `RateLimitTest` (webhook per-IP limit + boundary + per-IP isolation + **same-IP
login↔webhook bucket isolation (regression, both directions)**; per-route 30/min for
`user`/`logout`/`links store|delete`; bucket resets after the decay window; authenticated
bucket stays keyed by user id even when the client IP changes) + existing login/pay/resend
429 tests. *(2026-08-07; security notes: behind a reverse proxy the whole app shares one
client `ip()` unless trusted-proxies is configured at deploy — generic DoS ceiling either
way; per-IP granularity needs the Infra runbook step. Laravel's check-then-hit throttle is
non-atomic, so a concurrent burst can briefly exceed the cap by the in-flight request
count — bounded, since `DatabaseStore` increments serialize on `FOR UPDATE`; an
exactly-atomic ceiling needs `RateLimiter::attempt` with a reservation. Webhook
`header_spelling` diagnostic logging is now `app.debug`-gated so junk floods cannot spam
the log file.)*
