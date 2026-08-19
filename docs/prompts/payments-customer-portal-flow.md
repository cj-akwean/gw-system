# Prompt: Payments — Customer Portal Payment Flow (PayMongo)

> **Status:** This file is the **implementation spec**; work is tracked in
> ARCHITECTURE.md — Payments section (backend items, one sub-bullet per session) and
> Customer Portal section (UI, buildable later). The customer-portal UI plan below is
> **spec only**, not yet built — do **not** start the generic UI until the portal shell
> (dashboard + unpaid-bills list) exists. Copy-paste this whole file into the next session.
> Read `docs/summary/2026-08-03-invoice-pdf.md`, `docs/insights/product-decisions.md`
> (§11 billing decisions), and ARCHITECTURE.md's Payments section first.

## Context

- Backend: Laravel 13 + Filament 5 (admin) / Sanctum Bearer-token API (Next.js). Postgres.
- Frontend: Next.js 16 + React 19 + shadcn/ui + Tailwind v4. **Currently only a landing
  page + auth exist** (`src/app/page.tsx`, `src/app/auth/page.tsx`). No dashboard, no
  bills list, no portal shell to host a payment screen.
- Payments: PayMongo, **one-off** payments (not subscriptions). PDF already shipped via
  `PdfService::generate()` (reused to email the attachment on payment).
- Customer base = real Guinobatan water ratepayers (rural PH). High traffic = GCash/QR
  Ph; Google Pay etc. = showcase only, low expected usage.

## Why backend-first

A payment screen is unreachable today — the frontend has no portal shell to put it in,
so a generic UI would be a floating dead end and get rebuilt once the dashboard lands.
Backend PayMongo work (steps 1-4 below) is fully testable with curl + PayMongo test mode
and reuses the already-shipped `PdfService`. The UI waits on the portal shell; the flow
spec lives here so it isn't lost.

## Backend phase (active) — Payments checklist, ARCHITECTURE.md lines 210-213

1. PayMongo integration — create a payment intent/checkout per invoice (one-off).
   - Read PayMongo's QR Ph API (primary) and E-wallets / GCash redirect (secondary) docs
     before implementing — do not guess from memory.
2. PayMongo webhook route — **idempotent + signature-verified**:
   - Acknowledge immediately (HTTP 200 within 30s); do all real work in a **queued job** (mark `paid`, send email).
   - Verify the `X-Paymongo-Signature` (HMAC-SHA256 of the **raw** body with the endpoint secret). Note: docs inconsistently spell the header `Paymongo-Signature` vs `X-Paymongo-Signature` — log both in test to confirm which arrives.
   - Dedupe two ways: skip when the invoice is already `paid` **and** when the event ID was already processed; only ever transition `unpaid → paid`; store PayMongo's payment-intent id on the invoice.
   - Guard `livemode`; register **separate** endpoints for test and live.
3. Invoice marked `paid` on confirmed webhook (queued job in step 2 owns this).
4. Invoice PDF emailed to customer on payment (queued; reuses `PdfService::generate(Invoice)`).

> Build note: per AGENTS.md "verify against current docs", fetch the relevant PayMongo docs
> page for whichever API is being implemented. PayMongo's surface is broad and version-specific.

## Frontstage spec — customer portal payment flow (NOT built yet)

`Payment Method → Review & Pay` — 2 steps (Steam-style clarity, not a dropdown).

### Screen 1 — Payment Method (three tappable cards)

| Option | Behavior |
|---|---|
| **E-wallet** *(recommended badge, default/primary — two real PayMongo methods, not one w/ a sub-choice)* | **Scan QR code** → `qrph` method: you render the QR in-page from the Payment Intent's `next_action.code.image_url` (Base64). **10-min expiry** (`expiry_seconds: 600`; docs allow 60–9000s) — UI shows a live countdown driven by the backend deadline, never a hardcoded timer. **Open in GCash** → `gcash` method: you redirect the customer to `next_action.redirect.url`; PayMongo's own page shows the QR + "Open in GCash" button (mobile browser auto-handles the `gcash://` deep link; desktop shows QR only; 4-hr window, no countdown needed). No form fields either way. Read `payment-acceptance-qr-ph-api` + `payment-acceptance-e-wallets` before implementing. **Cap: e-wallet txns are ₱1.00–₱100,000.00** — irrelevant for residential bills, but means large commercial bills must go Card. |
| **Card** | Visa/Mastercard only (PH PayMongo card-network constraint). Steam two-section form below; see the PCI + 3DS note under the form. |
| **Digital Wallet** *(Google Pay)* | **Reverted to "Coming soon" *(2026-08-19)*** — the full flow was implemented (2026-08-18) but PayMongo sandbox offers no `test_url` simulator for `google_pay_card`, so the feature cannot be verified end-to-end. The card now renders disabled with "Coming soon" in the portal UI. Backend wiring remains for easy re-enablement. |

### Card form (if selected)

- **Card Info**: card number · expiration · security code (ⓘ "3-digit code on back").
- **Billing Information**: first name · last name · city · address · address 2 · zip ·
  country (default Philippines) · phone.

> **PCI-DSS note (not a style choice):** collect card details in your own inputs and POST them client-side to PayMongo's `/v1/payment_methods` using the **public key** (`pk_test_...`) — never to your Laravel backend. PayMongo's own [Best Practices](https://docs.paymongo.com/docs/payment-acceptance-best-practices) doc endorses exactly this ("Collect card details client-side and create a Payment Method using the public key"). Reality check: **not PCI-zero** — the PAN transits your DOM, so keep DOM/XSS hygiene tight and expect SAQ A-EP-equivalent obligations; PayMongo offers **no hosted iframe / tokenizing element**. Acceptable because Card is the only method that handles bills above the e-wallet ₱100k cap. The form can *look* like plain text fields, but after attach the intent may return `awaiting_next_action` with a **3DS redirect** (`next_action.redirect.url`): redirect the customer, then on `return_url` **re-fetch the intent server-side before marking paid — never trust redirect params alone.**

### Screen 2 — Review & Pay

- Invoice line item (account number, billing period, amount).
- Total.
- Selected payment method + **(Change)** link back to Screen 1 (Steam pattern).
- Pay button.
- After Pay: show a **pending** state until the webhook confirms. GCash redirect & Card 3DS return the customer to `return_url` *before* payment is final — do **not** mark paid on redirect; the webhook is the source of truth.
- On success: green state + receipt line ("Payment received — confirmation emailed to ... (Resend prod / Mailtrap dev)").

> UI inspiration (loose — Alipay screenshots were just reference, not a spec): a single-screen QR + summary with zero fields, a visible countdown driven by the backend deadline, plus success + expiry states; desktop 2-col → stacked on mobile. **Do not brand the QR screen GCash-only** — PayMongo's QR Ph is scanned by any PH e-wallet or banking app.

### Tooltips — desktop vs touch

- Desktop: hover ⓘ (Security Code, Digital Wallet, etc.).
- **Touch/mobile:** tap-to-toggle popover, not a ported hover tooltip. Adopt one consistent pattern and seed the (currently missing) `frontend-design` doc with it, so this isn't a dangling reference.

### Save-card checkbox — deferred

No "Save my payment information" checkbox until card vaulting is real. A checkbox that
does nothing yet is a broken promise. Build the form without it; add it in the same
release as vaulting.

## Build order (phased, one phase per session)

1. **Backend** (PayMongo intent + webhook + paid + PDF email) — active.
2. **Portal shell** (dashboard + unpaid-bills list) — blocks the UI.
3. **Card form + client-side Payment Method (public key).**
4. **Save-card + vaulting** — same release as #3, not before.
