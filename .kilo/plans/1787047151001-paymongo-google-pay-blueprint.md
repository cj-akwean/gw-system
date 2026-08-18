# PayMongo Google Pay (Digital Wallet) — Implementation Blueprint

Implement the unchecked `Digital Wallet (Google Pay)` item in `ARCHITECTURE.md` (line 265) as a real payment flow, mirroring the existing Card/QR Ph/GCash implementations. Google Pay is exposed by PayMongo as a first-class **digital wallet** (cards in the Google wallet, `google_pay_card` PM type — distinct from e-wallets). No PayMongo setup/monthly fees; only standard MDR on processed payments. Google charges no merchant fee.

All code executes on the client with the PayMongo **public** key (same posture as the existing card flow — no card/PAN data ever reaches the Laravel backend).

## Key decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| Trigger UX | The official Google Pay button is the **single tap-to-pay trigger** in the review step for `googlepay` — it replaces the SwipeButton (which stays for qrph/gcash/card). No intermediate swipe; no second button copy | Google UX guidelines require the branded GPay button itself to open the sheet; the user's requirement is explicitly a "tappable Google Pay button". |
| ₱100k cap | Google Pay is **NOT** subject to the e-wallet cap (`capExceeded`) — card-based, like Card. Must work on >₱100k bills | Same rationale as Card: "large commercial bills must go Card" — digital-wallet-over-card qualifies. |
| 3DS | `PAN_ONLY` may produce an issuer `awaiting_next_action` redirect OR in-sheet auth — handle both exactly like the card flow | Idiomatic reuse of the existing redirect-return + intent-status machine. |
| Recovery | Zero new server work — reuse `writePendingInvoice` + `resolveIntentStatus` + URL parsing | Method-agnostic by design (`api.ts:322-323` documents `method` as informational only). |
| Channel label | Add `google_pay_card` → "Google Pay" to the past-payments label map; title-case fallback covers any source variant PayMongo sends | Prevents the generic fallback from rendering "Google Pay Card". |
| Simulate harness | Dev-only `POST /api/dev/payments/simulate` (404 in prod) backed by an extracted `PaymentSimulationService` shared with the CLI; in-page "Simulate payment (test)" link rendered only with a `pk_test_` key AND an `onSimulate` prop | QR Ph's `test_url` simulator has no Google Pay equivalent and the GPay sheet can't open on the dev laptop — the CLI's webhook-dispatch logic is the only locally-exercisable half, and a browser button needs an HTTP surface to trigger it. |

---

## 0. Prerequisite checks (do these FIRST — block on failure)

1. **PayMongo dashboard Google Pay enablement** — Confirm Google Pay appears as an activatable/enabled payment method on the PayMongo dashboard for the account. PayMongo docs: *"Account configuration is required — contact PayMongo support if the Google Pay option doesn't appear in your dashboard."* If it is missing, contact PayMongo support and do not proceed to implementation.
2. **Google Pay Console business verification** — Required for **live** traffic. The merchant must submit the business and complete integration verification in the Google Pay Console (`https://pay.google.com/business/console`) and get a real merchant ID. Test-mode works without this (see below). Defer live rollout until the Console verification completes — a warning banner/Coming-soon state for Digital Wallet may remain until then.
3. **Test token availability** — In PayMongo test mode + Google Pay TEST environment, the Google Pay sheet shows test cards (e.g. `4111 1111 1111 1111` added in the Google Pay test app / browser test sheet). Tokens produced in the TEST environment are only accepted against `pk_test_...` keys. Verify the tester can actually open the Google Pay sheet in the dev browser (needs a Google account with a test card enrolled) before claiming the flow works end-to-end.
4. **Environment verification** — Dev must run over HTTPS or `localhost` (`window.isSecureContext === true`); Google Pay's JS requires a secure context. `localhost` qualifies; any LAN/dev URL over plain HTTP will fail Google's `IsReadyToPay`.

---

## 1. Backend changes

### 1.1 `backend/app/Services/PayMongoService.php`

**a. `VALID_PAYMENT_METHODS` const (lines 26–29)** — add `'google_pay_card'`:

```php
private const VALID_PAYMENT_METHODS = [
    'qrph', 'brankas', 'card', 'dob', 'billease',
    'gcash', 'grab_pay', 'shopee_pay', 'paymaya',
    'google_pay_card',
];
```

**b. `createPaymentIntent()` default methods (line 174)**:

```php
array $methods = ['qrph', 'gcash', 'card', 'google_pay_card'],
```

This makes every intent created via `POST /api/invoices/{id}/pay` allow attaching a `google_pay_card` PM. `validateMethods()` (line 638) then accepts it automatically because of (a). No other backend change is required — the webhook already records the source channel from the payment object, and the redirect-return intent-status endpoint (`POST /api/payments/intent-status`) is method-agnostic.

### 1.2 `backend/tests/Feature/PayMongoServiceTest.php`

**Line 78** — update the default-methods assertion:

```php
&& $attributes['payment_method_allowed'] === ['qrph', 'gcash', 'card', 'google_pay_card']
```

The `test_create_payment_intent_accepts_custom_methods` test (line 105) and the invalid-method rejection test are unaffected. Verify no other test asserts the old default list (`rg "qrph', 'gcash', 'card" backend/tests` should return only the line above).

---

## 2. Frontend changes

### 2.1 `frontend/src/lib/paymongo.ts` — one-line export only

`createPaymentMethod(type: string, extra: Record<string, unknown>)` (lines 70–83) already accepts arbitrary type strings and spreads `extra` into the `/payment_methods` attributes. Passing `"google_pay_card"` with `{ details: { token }, billing }` needs zero changes. `attachPaymentMethod` (lines 85–132) already handles `return_url` and normalizes `next_action` including `awaiting_next_action` redirects and `last_payment_error`.

The **only** edit here: `export function publicKey()` (currently module-private, line 12) so `GooglePayButton` can reuse it for `gatewayMerchantId` + test-env derivation as the single source of truth (§2.2 step 2). Existing callers are unchanged.

### 2.2 New component `frontend/src/components/portal/google-pay-button.tsx`

Minimal ambient types first in `frontend/src/types/payjs.d.ts` (Google ships no TS types): declare `window.google.payments.api.PaymentsClient`, `PaymentDataRequest`, `PaymentData`, `IsReadyToPayResponse` (keep minimal — `PaymentsClient`, `createButton`, `isReadyToPay`, `loadPaymentData`).

Component API (matches the pattern used by `CardForm`, controlled from `payment-method.tsx`):

```tsx
export function GooglePayButton({ onToken, disabled }: {
  onToken: (payment: { token: string; billing: { name: string; email: string } }) => void;
  disabled?: boolean;
})
```

Implementation responsibilities:

1. **Dynamic script loader** — inject `<script async src="https://pay.google.com/gp/p/ui/pay.js">`. Resolve **synchronously when `window.google?.payments?.api` already exists** (idempotent; also makes the component unit-testable by pre-stubbing the global), otherwise on script `onload`; reject on `onerror` **or** 8s timeout. Guard repeated loads (module-level promise cache) and be StrictMode-remount-safe (abort in cleanup). States: `"loading" | "ready" | "unavailable"`.
2. **Environment derivation** — single source of truth from the existing key, never separate config to drift:
   - Export `publicKey()` from `frontend/src/lib/paymongo.ts` (currently module-private, line 12) and reuse it in the component — `gatewayMerchantId` + env test check both read from it, so the two can never disagree.
   - `const test = publicKey().startsWith("pk_test_")` → `environment: test ? "TEST" : "PRODUCTION"`.
   - Missing/unset key → `unavailable` state (catch the throw; do not crash the pay screen — the other methods already show their own "not configured" behavior).
3. **Secure-context gate** — if `!window.isSecureContext`, set `unavailable` without calling Google (avoids silent script/API errors).
4. **`isReadyToPay`** with:
   - `allowedPaymentMethods: [{ type: "CARD", parameters: { allowedAuthMethods: ["PAN_ONLY"], allowedCardNetworks: ["VISA", "MASTERCARD"], billingAddressRequired: true, billingAddressParameters: { format: "MIN" }, emailRequired: true }, tokenizationSpecification: { type: "PAYMENT_GATEWAY", parameters: { gateway: "paymongo", gatewayMerchantId: publicKey } } }]` — `gatewayMerchantId` is the **PayMongo public key** (per PayMongo docs), NOT a Google merchant id. `PAN_ONLY` only (`CRYPTOGRAM_3DS` is still "coming soon" on PayMongo). Networks restricted to VISA/MASTERCARD.
   - Only when `resultType === "CAN_PAY"` → `ready`; else `unavailable`.
5. **merchantInfo** — `{ merchantName: "Guinobatan Waterworks", merchantId: test ? "TEST" : process.env.NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID }`. The production merchant ID comes from the Console verification (prerequisite 0.2); leave the env unset in test.
6. **Render** — official button via `paymentsClient.createButton({ onClick: handleClick, buttonColor: "black", buttonType: "pay", ... })` mounted into a host `<div ref>`. `data-testid="google-pay-button"` on the host. Click:
   - Guard `disabled` (busy ref) → no-op.
   - `paymentsClient.loadPaymentData(paymentDataRequest)` → `paymentMethodData.tokenizationData.token` + `paymentMethodData.info` (email + billingAddress) → call `onToken({ token, billing: { name: fullNameFromAddress, email } })`.
   - On `loadPaymentData` failure/cancel → surface a friendly inline error (Google returns typed errors; show only for non-user-cancelled).
7. **Unavailable/error render** — when `unavailable`: a disabled placeholder, `data-testid="google-pay-unavailable"`, text like "Google Pay isn't available on this device/browser right now." Permanent message behind `disabled` prop: also render a disabled placeholder while the parent flow is busy (the iframe-style GPay button cannot itself show disabled state).

### 2.3 `frontend/src/components/portal/payment-method.tsx` — wire the flow

Exact edits (current line numbers verified):

| # | Location | Change |
|---|---|---|
| 1 | Line 168 | `selectedMethod` union: `useState<"qrph" \| "gcash" \| "card" \| "googlepay" \| null>` |
| 2 | Line 64 | `QrState` error `flow` union: `"qrph" \| "gcash" \| "card" \| "googlepay"` |
| 3 | Import | Add `import { GooglePayButton } from "@/components/portal/google-pay-button";` |
| 4 | Lines 1078–1094 | Replace the disabled Digital Wallet `<div>` with a **tappable `<button>`** mirroring the Card button (lines 1055–1076): same `type="button"`, `data-testid="method-card-digital-wallet"`, `onClick={() => { setSelectedMethod("googlepay"); setStep("review"); }}`, `disabled={busy}` (NOT `ewalletDisabled` — Google Pay is card-based so the ₱100k e-wallet cap does NOT apply). Keep the Smartphone icon; copy: "Digital Wallet" / "Google Pay · Pay with cards saved in your Google account" |
| 5 | Lines 1155–1159 | "Paying with" label: add `: selectedMethod === "googlepay" ? "Google Pay"` branch |
| 6 | **App state — defined once, used once** | `const notHealthy = healthLoading \|\| (healthStatus != null && !healthStatus.healthy);` and `const payBlocked = busy \|\| qrLocked \|\| (selectedMethod === "googlepay" ? notHealthy : ewalletDisabled);` — Google Pay's trigger must NOT be blocked by `capExceeded`, only by busy/health (see row 8) |
| 7 | Lines 1196–1200 | Busy text: add googlepay branch → "Starting Google Pay…" (the `card` branch already exists) |
| 8 | Lines 1210–1226 (pay-trigger area) | **Single Google Pay button placement.** Change the enclosing condition (line ~1186) to use `payBlocked` instead of `busy \|\| qrLocked \|\| ewalletDisabled`, and in the `else` (not-blocked) branch: when `selectedMethod === "googlepay"` render `<GooglePayButton onToken={startGooglePay} disabled={busy} />` **instead of** the SwipeButton (which stays for qrph/gcash/card). The GPay button's own tap is the direct gesture that opens the sheet — do NOT also render it inside the review body |
| 9 | Line 1266 + 1270–1272 | QR placeholder condition `selectedMethod !== "card"` → exclude `"googlepay"` too; and for `googlepay` show "Tap the Google Pay button to continue." instead of the QR-copy hint |
| 10 | Lines 1277–1289 | Aside busy panel: the `attaching` branch hard-codes "Generating your QR code…" — make it method-aware; googlepay → "Connecting to Google Pay…" |
| 11 | Lines 1047–1052 | Cap note copy: "…Use Card for this bill." → "…Use Card or Google Pay for this bill." |

**New `startGooglePay` callback** (mirror `startCard` at lines 626–720 exactly):

```
startGooglePay = useCallback(async (payment: { token; billing }) => {
  if (healthStatus != null && !healthStatus.healthy) return;
  setQr({ phase: "starting" });
  try {
    const info = await startPayment(invoiceId);                       // fresh intent (409-guarded)
    const pm = await createPaymentMethod("google_pay_card", {
      details: { token: payment.token },
      billing: payment.billing,
    });
    setQr({ phase: "attaching" });
    const attached = await attachPaymentMethod({
      intentId: info.payment_intent_id,
      clientKey: info.client_key,
      paymentMethodId: pm,
      returnUrl: buildReturnUrl(invoiceId),                            // same ?from=redirect marker
    });

    if (attached.redirectUrl) {                                       // 3DS via issuer (PAN_ONLY)
      writePendingInvoice(invoiceId, { paymentIntentId: info.payment_intent_id, method: "googlepay" });
      window.location.assign(attached.redirectUrl);
      return;
    }
    if (attached.status === "succeeded" || attached.status === "processing") {
      writePendingInvoice(invoiceId, { paymentIntentId: info.payment_intent_id, method: "googlepay" });
      resolveIntentStatus(info.payment_intent_id).then((res) => {
        setQr({ phase: "idle" });
        if (res.status === "paid") { clearPendingInvoice(); setPaymentResult({ status: "paid", confirming: false }); return; }
        if (res.status === "confirmed" || res.status === "processing") {
          if (res.invoice_id != null) setResolvedInvoiceId(res.invoice_id);
          setPaymentResult({ status: "paid", confirming: true }); return;
        }
        if (res.status === "failed") { setScreen({ status: "failed" }); return; }
        setScreen({ status: "unconfirmed" });
      }).catch(() => { setQr({ phase: "idle" }); setScreen({ status: "unconfirmed" }); });
      return;
    }
    setQr({ phase: "error", message: attached.lastPaymentError ?? "The Google Pay payment wasn't accepted. Please try again.", flow: "googlepay" });
  } catch (err) {
    if (isUnauthorized(err)) { logout().then(() => router.replace("/auth")); return; }
    setQr({ phase: "error", message: err instanceof Error ? err.message : "We couldn't start the payment. Please try again.", flow: "googlepay" });
  }
}, [invoiceId, logout, router]);
```

This reuses 100% of the existing redirect-return recovery (URL `payment_intent_id` parsing → `POST /api/payments/intent-status` → ownership-gated invoice resolution), the pending marker (sessionStorage + localStorage, 1h TTL), the confirming/success modal family, and the 15s polling fallback — nothing new server-side.

### 2.4 `frontend/src/components/portal/payment-method.test.tsx`

1. **Mock the new module** at the top with the other `vi.mock` calls:
   `vi.mock("@/components/portal/google-pay-button", () => ({ GooglePayButton: ({ onToken }: { onToken: (p: any) => void }) => (<button type="button" data-testid="google-pay-button" onClick={() => onToken({ token: "gpay-token-1", billing: { name: "Maria Santos", email: "maria@example.com" } })}>Google Pay</button>) }))`.
   (Extend the `selectMethod` helper signature to accept `"googlepay"` — it clicks `method-card-digital-wallet`.)
2. **Update the existing card-render test (lines 519–529)**: keep `getByTestId("method-card-digital-wallet")`; remove `getByText("Coming soon")`; assert the digital wallet card is **not disabled** (`expect(screen.getByTestId("method-card-digital-wallet")).not.toBeDisabled()`).
3. **Update the ₱100k cap test (lines 531–543)**: add `expect(screen.getByTestId("method-card-digital-wallet")).not.toBeDisabled()` (card-based methods stay enabled over the e-wallet cap; `qr-ph-row`/`gcash-row` disabled as before). Optionally extend: over-cap bill + googlepay review shows the Google Pay button (cap does not block the trigger).
4. **New Google Pay flow test**: select digital wallet → review shows the Google Pay trigger → click →
   - `mockCreatePaymentMethod` called with `("google_pay_card", { details: { token: "gpay-token-1" }, billing: { name: "Maria Santos", email: "maria@example.com" } })`,
   - `mockAttachPaymentMethod` called with `{ intentId: "pi_1", clientKey: "ck_1", paymentMethodId: "pm_gpay_1", returnUrl: expect.stringContaining("from=redirect") }`.
5. **New 3DS-redirect variant**: `mockAttachPaymentMethod` resolves `{ status: "awaiting_next_action", redirectUrl: "https://pay.google.com/…", imageUrl: null, expiresAt: null, testUrl: null, lastPaymentError: null }` → assert `mockWritePendingInvoice` called with `{ paymentIntentId: "pi_1", method: "googlepay" }` and the `window.location.assign` spy receives the redirect URL (the file already installs an `assignSpy`).
6. **New succeeded variant**: attach resolves `{ status: "succeeded", …nulls }` → assert `mockResolveIntentStatus` called with `"pi_1"` and a `"paid"` resolution drives the success modal.

### 2.5 New `frontend/src/components/portal/google-pay-button.test.tsx` (real component coverage)

The payment-screen tests mock the component, so the component itself needs its own suite. Stub the global **before** mount (this is why the loader resolves synchronously when `window.google?.payments?.api` is already present):

- `afterEach`: `delete window.google` / restore stubs.
- `beforeEach`: set `process.env.NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY = "pk_test_..."`.
- Fake `PaymentsClient` class (vi.fn for `createButton` returning a DOM node, `isReadyToPay`, `loadPaymentData`).
- Tests:
  1. **Script API missing → unavailable**: no `window.google` → `google-pay-unavailable` rendered, no button; `loadPaymentData` never called.
  2. **Missing public key → unavailable**: unset env → unavailable state (no throw).
  3. **`isReadyToPay` false → unavailable**: set a fake `isReadyToPay` resolving `{ resultType: "NO_PAYMENT_METHODS" }` → unavailable.
  4. **Ready + user tap → token flows out**: `isReadyToPay` `CAN_PAY`; click the mounted button → `loadPaymentData` resolves a payment data fixture → `onToken` called with `{ token, billing: { name, email } }` (assert exact mapping from `info.billingAddress` + `info.email`).
  5. **`loadPaymentData` rejection (non-cancel) → inline error surfaced**; user-cancelled (`developers.google.com/pay/api/web/reference/client#PaymentsErrorStatus` `CANCELED`) → silent.
  6. **`disabled` prop → click is a no-op** (guard short-circuits before `loadPaymentData`).
  7. **Environment flag**: with `pk_test_` key the `PaymentsClient` receives `{ environment: "TEST" }`; assert `tokenizationSpecification.parameters.gatewayMerchantId === "pk_test_..."`.

### 2.6 `frontend/src/components/portal/past-payments.tsx` — digital-wallet label

`paymentMethodLabel` (lines 32–42) maps `channel: "gcash" | "qrph" | "card"` and falls back to title-casing the raw string. Add `if (channel === "google_pay_card" || channel === "googlepay") return "Google Pay";` before the raw fallback. In `past-payments.test.tsx` (existing label tests ~lines 126–142) add an assertion for the `google_pay_card` channel. Keeps the past-payments drawer correct regardless of the exact source string PayMongo's webhook records.

### 2.7 `frontend/.env.example`

Add `NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID=` (empty in test; filled with the Console-verified merchant ID at live rollout) with a one-line comment that it is only required for production Google Pay.

---

## 3. Traps & edge cases → mitigations

| # | Trap / edge case | Mitigation |
|---|---|---|
| 1 | **pay.js load failure / blocked (ad-blocker, CSP, network)** or `PaymentsClient` unavailable in an unsupported browser/region | Dynamic loader with `onerror` + 8s timeout → `unavailable` state → `data-testid="google-pay-unavailable"` disabled placeholder with copy; **other methods remain fully usable**; never throw out of the payment screen. Do not hard-block the flow on the script. |
| 2 | **Non-secure context** (LAN IP / plain HTTP dev URL) | Explicit `window.isSecureContext` gate before touching Google APIs; show unavailable state. Document that live must be HTTPS. (Localhost is exempt.) |
| 3 | **Test/live tokenization mismatch** — a TEST Google Pay token attached to a live price key (or vice-versa) fails attach (400/404) | Environment derived **only** from `NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY` prefix (`pk_test_` → TEST). Google-Card sheet's TEST env also keyed to that. Test tokens can never reach a live intent. Live smoke test (validation below) proves the live path. |
| 4 | **3DS inconsistency for PAN_ONLY vs the existing card flow** — some PAN_ONLY issuers authenticate *inside* the Google Pay sheet (CVC/fingerprint, no redirect from PayMongo) while others produce `awaiting_next_action` redirect | Handle **both** paths: redirectUrl → assign + pending marker (exact card behavior); non-redirect `succeeded`/`processing` → `writePendingInvoice` + `resolveIntentStatus` + confirming modal. Never assume a redirect will occur. |
| 5 | **Intent desync if interrupted mid-flow** (user abandons after attach, refresh after 3DS, return to stripped URL) | No new server work: reuse `writePendingInvoice` (sessionStorage **and** localStorage, 1h TTL, read-twice safe), URL `payment_intent_id` parsing on return, and `POST /api/payments/intent-status` ownership-gated resolution — all already implemented for cards (mount effect, lines 197–213). Google Pay is not special here. |
| 6 | **Double-tap / re-created intents on retry** | `getOrCreatePaymentIntent` already locks and returns the live intent (409 double-pay guard; 404/410-only refresh), and the review-step button is gated by `busy`/`disabled`. `loadPaymentData` guarded by a `starting` ref inside `GooglePayButton`. |
| 7 | **`gatewayMerchantId` / `merchantId` mixup** — using the Google merchant id as the PayMongo gateway id (button renders but attach always fails) | `gatewayMerchantId` = the exported `publicKey()` from `paymongo.ts` (per PayMongo docs); separate `merchantInfo.merchantId` from Console verification (`"TEST"` in test env). |
| 8 | **Card-network mismatch** — `allowedCardNetworks` set broader than PayMongo supports | Restrict to `["VISA", "MASTERCARD"]`; Amex etc. would render in the sheet but fail attach → keep them out. |
| 9 | **Billing-shape mismatch** — Google's `billingAddress`/`email` don't match PayMongo's `billing` object → attach 400 | Build the `billing` object explicitly in `startGooglePay` (`name` from name/address parts joined, `email` from `info.email`); every field nullable-safe. (Details-only token PM per PayMongo docs.) |
| 10 | **Test-card availability** — tester has no Google Pay test card enrolled → sheet shows no card | Prerequisite 0.3. Use the Google test card enrollment flow (`4111 1111 1111 1111`); confirm in the Google Pay sheet before claiming success. |
| 11 | **Cap-sensitivity regression** — Google Pay accidentally treated as an e-wallet (disabled > ₱100k) or QR hint shown for it | Edit table rows 4, 8, 9 above: digital wallet card uses `busy` (not `ewalletDisabled`); QR placeholder excludes `googlepay`; SwipeButton replaced by the GPay button only for `googlepay`. |
| 12 | **CSP added later** blocks `https://pay.google.com` | Note in `ARCHITECTURE.md`: if a CSP is ever introduced, allow `pay.google.com` (script-src) + Google Pay origin. No CSP exists in this repo today. |
| 13 | **StrictMode double-mount** firing the script loader/buttons twice (dev-only) | Idempotent loader (module-level promise cache) + effect cleanup; `createButton` mounted into a cleared host div. |
| 14 | **Duplicate button regression** — someone later adds a second Google Pay button into the review body while the trigger area also renders one (as this draft's first pass did at 1172-1178) | Single placement is explicit in the edit table (row 8); the review-body insertion is deleted. Adding a code comment on the trigger-area render ("Google Pay has exactly one trigger — the GPay button below") guards future edits. |
| 15 | **Channel-label variant** — PayMongo webhook may record `google_pay_card`, `googlepay`, or another variant as `payments.paymongo_source`; the past-payments map misses it | §2.6 maps the known strings AND keeps the title-case fallback — any unknown variant degrades to readable text, never a crash or blank. |
| 16 | **Cap regression via `ewalletDisabled`** — the pre-existing pay-trigger condition `busy \|\| qrLocked \|\| ewalletDisabled` would hide the Google Pay trigger on >₱100k bills (since `ewalletDisabled` includes `capExceeded`) | §2.3 row 6 re-derives it method-aware (`payBlocked`); covered by the cap test (test 3). |
| 17 | **Simulate firing in production** — the webhook path is real; a stray tap would mark a live invoice paid | Double gate: frontend link requires BOTH `onSimulate` (never wired in prod) AND a `pk_test_` key; backend 404s when `app()->environment('production')`. Covered by the 404 controller test (§4.1e). |
| 18 | **Simulate on an already-paid invoice** — would try to record a second payment | 409 (mirrors `InvoicePaymentController`); the UI surfaces the message in the qr error state; the poll never flips anything on a 409. |
| 19 | **Success email silently missing** — simulate marks the invoice paid but the confirmation email never arrives when no queue worker is running | Documented in the e2e steps (worker required — §5.2 step 1); identical constraint as the real flow and the QR simulator. |

---

## 4. Dev simulate payment (test harness for laptop development)

**Problem:** On the developer laptop the Google Pay sheet can't open (`state === "unavailable"` renders "Google Pay isn't available on this device/browser right now." — `google-pay-button.tsx:279-290`), and PayMongo offers no `test_url` simulator for `google_pay_card` (unlike QR Ph). So the attach → outcome leg can never be exercised locally. The QR Ph precedent has two halves: a frontend "Simulate payment (test)" button + 2s polling (`testPaymentPending`, `payment-method.tsx:367-371, 1450-1462`), and a backend CLI `paymongo:simulate-payment` that dispatches a real `payment.paid` webhook payload through `ProcessPayMongoWebhook`. Google Pay's simulate reuses both halves with the CLI logic exposed over a dev-only HTTP endpoint (a browser button cannot invoke a CLI).

**Coverage this gives:** the webhook-crediting path (`payment.paid` → `PaymentService::markPaidFromWebhook` → Payment row → queued confirmation email), the UI outcome machine (2s poll → success modal), and the admin/channel labels. **Not covered (known limitation, mirror of QR's):** the actual Google Pay sheet, token decryption, and PM attach — those still require an available GPay environment + enrolled test card.

### 4.1 Backend

**a. Extract the simulator into a service (`backend/app/Services/PaymentSimulationService.php`)** — move the payload-build + dispatch logic out of `PayMongoSimulatePaymentCommand::handle()` (lines 49-98) into:

```php
public function simulate(Invoice $invoice, string $source = 'card', ?array $payer = null): array
```

- Throws a domain exception when the invoice isn't `unpaid|overdue` (the CLI currently error-returns; the controller maps it to 409).
- Fabricates `pi_sim_` + random intent id when `paymongo_payment_intent_id` is null (identical to CLI lines 59-63).
- Dispatches **synchronously** — `(new ProcessPayMongoWebhook($payload))->handle()` — exactly like the CLI (deterministic status for the polling UI; the confirmation email is still queued by the job itself).
- Returns `[paymentId, eventId, payer]`; CLI prints these, controller JSON-encodes them.

**b. Refactor `PayMongoSimulatePaymentCommand`** to call the service (keep the same signature, output, and all existing assertions in `PayMongoSimulatePaymentCommandTest` green — that suite asserts behavior, not internals).

**c. New controller `backend/app/Http/Controllers/Api/DevPaymentSimulationController.php`** with `store(Request $request, Invoice $invoice, PaymentSimulationService $service)`:

1. `abort_unless(! app()->environment('production'), 404)` — the endpoint does not exist in production (double-guard with the frontend rule below).
2. Ownership guard identical to `InvoicePaymentController` (lines 26-33): active link to `$invoice->service_connection_id` → else 403.
3. Payable guard: `in_array($invoice->status, ['unpaid', 'overdue'])` → else 409 (same messages as `InvoicePaymentController`).
4. `$service->simulate($invoice, 'google_pay_card')` → 200 `{ message, payment_id, event_id, source: 'google_pay_card' }`.
5. Domain exception → 422; unexpected → `report()` + 500.

**d. Route** — inside the `auth:sanctum` group in `backend/routes/api.php`:

```php
Route::post('/dev/payments/simulate', [DevPaymentSimulationController::class, 'store'])
    ->middleware('throttle:20,1,payments-simulate');
```

**e. Tests — `backend/tests/Feature/DevPaymentSimulationControllerTest.php`** (RefreshDatabase, `actingAs` a linked portal user):
- 200: owned unpaid invoice → `paid`, `Payment` row with `paymongo_source = 'google_pay_card'`, `payment_id`/`event_id` returned, `SendPaymentConfirmationEmail` pushed (Queue::fake).
- 403: user linked to a different connection.
- 409: invoice already `paid`.
- 404: production sim — `putenv('APP_ENV=production'); $this->refreshApplication();` then the same request 404s (restore env in teardown).
- Service-level: `PaymentSimulationServiceTest` covers intent fabrication/reuse + source recording (or fold into the existing command test file).

### 4.2 Frontend

**a. `frontend/src/lib/api.ts`** — add:

```ts
export async function simulatePayment(
  invoiceId: number | string
): Promise<{ payment_id: string; event_id: string }> {
  const res = await authFetch("/api/dev/payments/simulate", {
    method: "POST",
    body: JSON.stringify({ invoice_id: invoiceId }),
  });
  return res.json();
}
```

**b. `frontend/src/components/portal/google-pay-button.tsx`** — add an `onSimulate?: () => void` prop; render a test-only link when it's a test key and the link is safe to show:

- Derive `const isTest = (() => { try { return publicKey().startsWith("pk_test_"); } catch { return false; } })();`
- In the **unavailable** branch (lines 279-290), wrap the disabled button in a `<div>` and, when `isTest && onSimulate`, render below it a `<button type="button" data-testid="google-pay-simulate" onClick={onSimulate} className="...dashed-border muted...">Simulate payment (test)</button>` (mirror the QR button styling, `payment-method.tsx:1450-1462`).
- In the **main return** (lines 305-319), render the same link under the host/loading/error block under the same condition.
- The `disabled` early-return (lines 292-303) stays link-free (parent busy — no double-fires).
- Prod safety is structural: live key → `isTest` false; and `onSimulate` isn't passed in prod (see c) — the link cannot appear even if `key` were misconfigured.

**c. `frontend/src/components/portal/payment-method.tsx`** — in the Google Pay trigger block (line ~1325):

- Add `startSimulateGooglePay` (fire-and-poll, deliberately does NOT touch `qr` phase — no QR UI exists for Google Pay):

```
const startSimulateGooglePay = useCallback(async () => {
  if (healthStatus != null && !healthStatus.healthy) return;
  try {
    await simulatePayment(invoiceId);
    setTestPaymentPending(true);          // existing effect polls at 2s and shows the success modal
  } catch (err) {
    if (isUnauthorized(err)) { logout().then(() => router.replace("/auth")); return; }
    setQr({ phase: "error", message: err instanceof Error ? err.message : "Couldn't run the test payment. Please try again.", flow: "googlepay" });
  }
}, [invoiceId, logout, router]);
```

- Pass `onSimulate={startSimulateGooglePay}` to `<GooglePayButton …>`.

The existing `testPaymentPending` machinery (`payment-method.tsx:346-378`) then does the rest: 2s polling → the invoice leaves the unpaid list after the webhook credits it → `clearStoredQr` + success modal. No changes to the polling effect.

**d. Tests — `frontend/src/components/portal/google-pay-button.test.tsx`:**
- With `pk_test_` key + `onSimulate` provided: `google-pay-simulate` present in the unavailable state and in the ready state; click invokes `onSimulate`.
- Live key (`pk_live_…`) or no `onSimulate` prop: link absent.
- `disabled` prop: link absent.

**e. Tests — `frontend/src/components/portal/payment-method.test.tsx`:**
- Mock `simulatePayment` in the `@/lib/api` mock; extend the `GooglePayButton` mock to render a `google-pay-simulate` button that calls `onSimulate`.
- New test (mirror of the QR simulate fast-poll test, lines 691-717): googlepay review → click simulate → `mockSimulatePayment` called with `"1"` → advance 2s with the invoice removed from the unpaid list → success modal.

---

## 5. Validation

### 5.1 Test environment (pre-commit)

- Backend unit: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter PayMongoServiceTest` in `backend/` (or `docker compose exec backend gw-test -- --filter PayMongoServiceTest`). Assert default-methods test green. Plus the new `DevPaymentSimulationControllerTest` + `PaymentSimulationServiceTest` (or extended command test) — all 4 simulate cases (200/403/409/404) + intent fabrication/reuse.
- Frontend: `npm test` (Vitest) in `frontend/` — payment-method suite (incl. Google Pay flow/3DS/succeeded/simulate variants), the new `google-pay-button.test.tsx` suite (incl. simulate-link cases), and the updated `past-payments` label test; `npm run lint`; `npm run build` (static export must succeed with the new component).
- Same-commit doc updates:
  - `ARCHITECTURE.md` line 265 → check it off: `[x] Digital Wallet (Google Pay)` with a details pointer to the new implementation-note section.
  - `ARCHITECTURE.md` Webhook simulation bullet (line ~149) → mention the Google Pay dev simulate endpoint (`POST /api/dev/payments/simulate`, non-production only) alongside the CLI.
  - `docs/insights/implementation-notes.md` → add a Payments-section note (token flow, env derivation, single-trigger decision, cap exemption, simulate harness).
  - `docs/insights/product-decisions.md` → dated entries for the three decisions: GPay-button-as-single-trigger (vs Swipe-to-pay), Google Pay card-based cap exemption, and the dev-only HTTP simulate endpoint (CLI logic exposed for the browser harness; never ships live).
  - `docs/prompts/payments-customer-portal-flow.md` → replace the Google Pay "Coming soon" gating paragraph with the implemented flow.
  - `docs/manual-tests/paymongo-payment-e2e.md` → add a Google Pay section (test-card steps + the laptop fallback: use the in-page Simulate button → expect success modal in ~2s + payment row + receipt email).
  - `docs/summary/YYYY-MM-DD-google-pay.md` → session summary.
- Run `graphify query` before editing `payment-method.tsx` and `PayMongoSimulatePaymentCommand.php` (shared files with callers) per AGENTS.md Rule 3.

### 5.2 End-to-end (dev, test keys)

1. `php artisan serve` + `php artisan queue:work --tries=3` + `npm run dev` (queue worker required for the confirmation email half).
2. Portal login (`test@example.com` / `password`) → Pay on an unpaid seeded invoice → Digital Wallet card → review.
3. **With the laptop's GPay sheet unavailable** (the reported case): the review shows "Google Pay isn't available on this device/browser right now." **plus** the "Simulate payment (test)" link → tap it → expect the success modal within ~2s, a `Payment` row with `paymongo_source = 'google_pay_card'`, the Portal drawer labeling it "Google Pay", and the receipt email queued (process with the worker → Mailtrap).
4. **If the sheet CAN open**: Google Pay sheet (test env) → select the enrolled test card → authenticate → same assertions via the real attach path.
5. Backend-only sanity after the refactor: `php artisan paymongo:simulate-payment {invoice} --source=google_pay_card` still works and prints payment/event ids.

### 5.3 Live rollout (after Console verification, prerequisite 0.2)

1. Set `NEXT_PUBLIC_PAYMONGO_PUBLIC_KEY` to the **live** key and `NEXT_PUBLIC_GOOGLE_PAY_MERCHANT_ID` to the verified Console merchant ID; rebuild.
2. Confirm PayMongo dashboard shows Google Pay live-enabled (contact support if absent).
3. Run one real payment of a small amount with a real Google Pay-enrolled card: sheet → authenticate → webhook credits invoice → receipt emails → admin payment row shows the Google Pay channel. Verify `payments.paymongo_source` shows the expected source value.
4. Verify the PDP/UI copy: Digital Wallet card enabled, no "Coming soon" anywhere, cap note mentions "Card or Google Pay", large-bill (>₱100k) flow works via Google Pay.

## 6. Out of scope

- `CRYPTOGRAM_3DS` Google Pay tokens (PayMongo "coming soon") — nothing to implement; guard is `PAN_ONLY` only.
- PayMongo Checkout-API/Shopify/Pages/Links integration for Google Pay — this app uses the direct Payment Intent API.
- Vaulting Google Pay tokens as saved cards (`saved_payment_methods`) — the existing card-vault path is card-specific and not requested.
- Reconcile/admin-report changes for the new channel (webhook already records the source generically).
- Simulating the Google Pay sheet, token decryption, or PM attach — PayMongo offers no `test_url` for `google_pay_card` (unlike QR Ph), so those legs require a real GPay environment + enrolled test card. The simulate harness (§4) intentionally starts downstream of attach, exactly like the existing QR simulator only covers "after the code was scanned / attach returned a test page".