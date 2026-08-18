# SMS Notifications — Semaphore (OTP delivery channel)

> **Status update (2026-08-18, after first implementation):** the original plan below
> (§Manual steps → §Out of scope) is **implemented and uncommitted** — `git status`
> shows the expected file set (SmsService, SendOtpSms, OtpService, both OTP controllers,
> profile/phone plumbing, `/api/health/sms`, frontend toggles) and the touched test
> suite is green (backend 54/54 filtered, frontend 211/211). **The gap that surfaced
> afterwards is Semaphore has no test sandbox** — every message costs real credits and
> needs a real PH number. The amendment at §Amendment is the remaining work: a
> `log` SMS driver for dev/test, mirroring the project's existing `MAIL_MAILER=log`
> convention (README.md:88 "writes every email to storage/logs instead of sending").
> Execute the amendment on top of the current (uncommitted) working tree.

## Goal

Add SMS as an **optional delivery channel** for the app's own verification OTPs
(password change + password reset). Email stays the default/primary channel —
SMS is a user-selectable *choice* beside it. This unblocks customers who have
phone but no reliable email.

## Scope clarification (read first)

**Card (Visa/Mastercard) 3DS OTPs are NOT in scope and CANNOT be sent by this app.**
When a customer pays by card, the bank sends the 3DS challenge/OTP to the
cardholder via the card network — PayMongo and this app never see or control it.
No amount of app-side SMS work changes that. This implementation only covers the
OTPs the **app itself** generates: password-change codes (`OtpService`) and
password-reset codes (Laravel broker + `OtpTokenRepository`).

Decided with the user:
- Provider: **Semaphore** (PH provider, ≈ ₱2/OTP credit, PH-only numbers).
- Scope: **OTP codes only** — no payment receipts, no billing reminders.
- SMS is **an option, not the primary** channel (email stays default).
- Phone numbers are **collected in the portal** (they are not stored today).

## Manual steps — Semaphore signup & setup (user does these once)

1. Go to **https://semaphore.co** → **Sign up** (email, password, company name,
   reason for using).
2. Confirm the verification email, then log in.
3. **Add credits**: Dashboard → "Add credits" / Buy SMS package (prepaid, one
   credit ≈ one regular SMS; the OTP route costs **2 credits per 160-char SMS**).
   A deposit usually unlocks sending.
4. **Sender Name**: Account/Settings → **Sender Names** → add one, e.g.
   `GW-SYSTEM` (alphanumeric, ≤11 chars). New names are submitted to telcos for
   approval and can only send to your own number until approved.
5. **API key**: Dashboard → **API Tokens** → create a key (copy it — shown once).
6. **Smoke test**: use the web tool (semaphore.co/send) or a `curl` POST to send
   a test SMS to your own mobile. Messages must not start with "TEST" (silently
   dropped).
7. Put the key + sender name into `backend/.env` (values below). SMS stays
   disabled until the key is set.

Verified API surface (fetched from semaphore.co/docs, 2026-08-18):
`POST https://api.semaphore.co/api/v4/otp` — form params `apikey`, `number`,
`message` (with `{otp}` placeholder), `code` (our own code), `sendername`.
Returns JSON array; status `Pending`/`Sent` = accepted, `Failed` = rejected.
No auth headers; plain-form POST. Account balance: `GET /api/v4/account`.

## Backend changes

### 1. Config + env
- `backend/.env.example`: add
  ```
  SEMAPHORE_API_KEY=
  SEMAPHORE_SENDER_NAME=GW-SYSTEM
  ```
- `backend/config/services.php`: add a `semaphore` block
  (`api_key`, `sender_name`, `otp_endpoint` = `https://api.semaphore.co/api/v4/otp`).
- `backend/config/logging.php`: add a dedicated `sms` channel (single,
  `storage/logs/sms.log`) mirroring the `paymongo` channel — tests point it at a
  throwaway path in `phpunit.xml`.

### 2. New service — `backend/app/Services/SmsService.php`
Mirror the `PayMongoService` HTTP pattern (connect-timeout, retry, failure log).
- `sendOtp(string $phone, string $code, string $message): void` — normalizes the
  phone (strip spaces/dashes/`+`; `09…` → `639…`), POSTs to the OTP endpoint with
  `message` containing the `{otp}` placeholder and `code` = our code, logs to the
  `sms` channel, throws `RuntimeException` on failure (job retries).
- `available(): bool` — true only when `SEMAPHORE_API_KEY` is set (drives UI
  visibility).
- Phone normalization helper — accept `09XXXXXXXXX` / `639XXXXXXXXX` /
  `+639XXXXXXXXX`; throw on invalid PH format.

### 3. New job — `backend/app/Jobs/SendOtpSms.php`
`ShouldQueue`, `tries = 3`, `backoff [10, 30, 60]` (matches the email job).
Constructor: `(string $phone, string $code, string $message)`. On permanent
failure: log + `AdminNotifier::notify('SMS delivery failed', …)` (same pattern as
`SendPaymentConfirmationEmail::failed()`).

### 4. `backend/app/Services/OtpService.php`
- Add `public const PASSWORD_RESET = 'password_reset';` (optional; see 6).
- `send(User $user, string $purpose, string $channel = 'email'): void`:
  - `email` (default): existing behaviour.
  - `sms`: require `$user->phone` (throw `InvalidArgumentException` if missing),
    generate + cache the hashed code as today, then
    `SendOtpSms::dispatch($user->phone, $code, $message)` where the message is
    `"GW-System verification code: {otp}. Expires in 5 minutes. Do not share."`.
- Store the channel in the cache payload so `verify()` is channel-agnostic
  (verification is identical).

### 5. `POST /api/password/send-code` — `ChangePasswordController@sendCode`
- Accept optional `channel` (`email` default | `sms`), validated.
- If `sms` and `$user->phone` empty → 422: `"Add a phone number in Settings first."`
- Response message becomes channel-aware:
  `"Verification code sent to your email."` / `"…sent to your phone (…)."`
- Keep `throttle:5,1,password-send-code`.

### 6. `POST /api/forgot-password` — `ForgotPasswordController@store`
- Accept optional `channel`; keep the **generic anti-enumeration response** either way.
- Inside the broker callback we already receive the user: if `channel === 'sms'`
  and the user has a phone → `SendOtpSms::dispatch($phone, $token, ...)` (the
  broker token IS the 6-digit code); otherwise fall back to the existing email
  (`PasswordResetOtp`). No double-send. Brokered throttle (60s per email) still
  applies.

### 7. Profile — collect phone
- `backend/app/Http/Requests/UpdateProfileRequest.php`: add optional `phone`,
  nullable, `max:20`, PH-mobile format rule (loose: `09…` or `+639…`).
- `backend/app/Http/Controllers/Api/ProfileController.php`: pass `phone` through,
  include it in the JSON response (login/register responses already return the
  user shape; add `phone` to the public user payloads too).

## Frontend changes

### 8. `src/lib/api.ts`
- `PortalUser` + `phone: string | null`.
- `updateProfileApi(name, avatarId, phone?)` — send `phone` in the PATCH body.
- `sendPasswordChangeOtp(channel: "email" | "sms")`.
- `sendPasswordResetOtp(email, channel: "email" | "sms" = "email")`.

### 9. `src/components/kokonutui/avatar-picker.tsx` (ProfileSetup)
Adapted component (already forked): add optional `initialPhone` prop and an
optional phone `Input` (rendered only when a new `withPhone` boolean prop is
true — onboarding stays untouched). `onComplete` gains `phone: string`. Input is
optional/cleared to null when empty.

### 10. `src/app/settings/page.tsx` — profile + password sections
- Pass through phone via `ProfileSetup` (saved with the same Save button).
- In **Change password**: add a small channel choice (Email · SMS radio or
  segmented toggle) shown next to "Send verification code". When SMS is chosen and
  the user has no phone, show an inline hint linking to the profile field above.
  Update the helper text: `"Check your email — the code expires in 5 minutes."`
  → channel-aware (`"Check your phone…"` for SMS). Never surface SMS when
  `SmsService::available()` is false (frontend can't call PHP — expose
  availability via the `/api/health/payment`-style approach: add a lightweight
  `/api/health/sms` endpoint returning `{available: bool, hasPhone: bool}` so the
  settings page can hide the SMS option). 
- `handleSendOtp` passes the chosen channel.

### 11. `src/app/forgot-password/page.tsx`
- Add an optional "Send by SMS instead" checkbox/toggle; enabled only when the
  user has a linked portal account with a phone (checked via the same
  `/api/health/sms`-style availability endpoint, keyed by the entered email — or
  simply always send, letting the backend fall back to email; pick the simpler
  always-allow + backend-fallback unless the user prefers a checked state).
- Keep the generic success copy.

### 12. new endpoint — `GET /api/health/sms`
`HealthController` addition returning `{available, hasPhone}` for the signed-in
user; throttled like `/api/health/payment`.

## Tests

Backend (run touched files only):
- `SmsServiceTest` — phone normalization, payload shape, non-2xx/network throw,
  `available()` gating on env.
- `OtpServiceTest` — sms channel dispatches `SendOtpSms` with the right code;
  sms without phone throws; email path unchanged; verify() still works.
- `ChangePasswordControllerTest` — sms channel with/without phone; response text.
- `ForgotPasswordControllerTest` — sms path dispatches SMS with the broker token;
  no-phone falls back to email; generic response preserved.
- `ProfileControllerTest` — phone saved + returned; invalid phone rejected.

Frontend:
- `settings/page.test.tsx` — channel toggle, phone hint, SMS hidden when
  unavailable, helper text.
- `forgot-password/page.test.tsx` — SMS toggle + backend fallback.
- `api.test.ts` — new request/response shapes.

## Docs (this project's workflow)

- `ARCHITECTURE.md`: tick `- [ ] SMS notifications wired up (Semaphore/Twilio)` →
  `- [x]` with a one-liner + `(details: … → §SMS)` pointer.
- `docs/insights/implementation-notes.md`: new `### SMS §` section.
- `docs/insights/product-decisions.md`: new dated entry — "Why SMS is an optional
  OTP channel, not primary" + the 3DS clarification + Semaphore-vs-Twilio choice.
- `README.md` env-vars table: add the two `SEMAPHORE_*` rows; "External service
  dashboards" section: Semaphore sign-up.
- `docs/summary/YYYY-MM-DD-topic.md`: session summary at the end.

## Validation

1. With `SEMAPHORE_API_KEY` empty: `/api/health/sms` → `available:false`;
   settings page hides the SMS option; nothing calls Semaphore.
2. With a test key + credited account: settings → add phone → change password →
   choose SMS → code arrives on the phone → submit → password changed. Resend
   works. Invalid code / 5 attempts still blocked (unchanged `OtpService::verify`).
3. Forgot-password → "Send by SMS" → code on phone → reset succeeds; a second
   request within 60s is throttled.
4. `php -l` on changed files; run touched test files via
   `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter=…`;
   frontend `npm test` + `npm run build` (static export must still pass).
5. Semaphore dashboard shows the OTP messages (status `Sent`) and balance
   debited 2 credits each.

## Out of scope

- Payment receipts / billing reminders via SMS (future).
- SMS for admin alerts (bell already covers it).
- Semaphore balance-check CLI / auto-refill.
- Twilio driver or an abstraction layer over multiple providers (Semaphore stays
  the only real driver; the log driver below is NOT a provider, it's a dev/test
  transport).
- Verifying the user's phone (no OTP-verify-on-save; phone is self-asserted).

---

# Amendment — dev/test sandbox via a `log` SMS driver

## Problem

Semaphore has **no sandbox or test mode** (verified against semaphore.co/docs,
2026-08-18). Any call to the real endpoint sends a live SMS on the OTP route
(2 credits ≈ ₱2) to a real PH number. The just-finished implementation is tested
only via `Http::fake`, so none of it has been exercised for real — and a developer
without a credited Semaphore account (or a PH phone) cannot verify the flow at all.

## Decision

Mirror the project's existing email convention — `MAIL_MAILER=log` writes emails
to `storage/logs` in dev instead of delivering them (README.md:88). Add an
`SMS_DRIVER` env switch (`log` | `semaphore`):

- **`log` (default in dev)** — `SmsService` writes the message (phone + code +
  interpolated body) to the `sms` log channel (`storage/logs/sms.log`) and returns
  without calling Semaphore. `available()` stays `true` so the portal shows the SMS
  option and the whole flow works offline, end to end. **Zero cost, no account, no
  PH number needed.**
- **`semaphore` (production)** — current behaviour; `available()` gates on the key.
- Config default: `env('SMS_DRIVER', app()->environment('production') ? 'semaphore' : 'log')`
  — prod defaults to the real driver (with no key, SMS is simply unavailable),
  dev/test default to the sandbox. Explicit `SMS_DRIVER` always wins.

## Changes (on top of the current uncommitted tree)

### A. Config + env
- `backend/config/services.php` `semaphore` block: add
  `'driver' => env('SMS_DRIVER', app()->environment('production') ? 'semaphore' : 'log')`.
- `backend/.env.example`: replace the current Semaphore block with
  ```
  # SMS delivery driver: log (dev — writes to storage/logs/sms.log instead of
  # sending; read the code from the file) or semaphore (prod — real SMS; needs
  # the API key below). Defaults to log in local/test, semaphore in production.
  SMS_DRIVER=log
  SEMAPHORE_API_KEY=
  SEMAPHORE_SENDER_NAME=GW-SYSTEM
  ```
  Update the surrounding comment (currently says "Leave SEMAPHORE_API_KEY empty and
  SMS stays disabled" — false once the log driver exists).

### B. `backend/app/Services/SmsService.php`
- Add `public function driver(): string` returning `config('services.semaphore.driver')`.
- `sendOtp()`: branch on `$this->driver()`:
  - `log`: `Log::channel('sms')->info('SMS OTP (log driver — not sent)', ['phone' => $normalized, 'code' => $code, 'message' => str_replace('{otp}', $code, $message)])` and `return`;
  - `semaphore`: existing POST path.
- `available()`: return `true` when driver is `log`; otherwise the existing key gate.
- Keep `normalizePhone()` throwing for invalid numbers in BOTH drivers (the log
  driver must still validate, so tests exercising a bad number fail the same way).

### C. New dev command — `backend/app/Console/Commands/SmsTestCommand.php`
`php artisan sms:test {number? : PH mobile — defaults to 09171234567} {--code= : fixed code, default random}`
— sends one OTP through the active driver, then prints where it went:
- log driver: `Code written to storage/logs/sms.log — open that file to read it.`
- semaphore: `Sent via Semaphore (per normal).`
Mirrors the existing `paymongo:simulate-payment` dev-command pattern. Registered
automatically as a command; harmless in prod (it just sends one real SMS if run
there with the semaphore driver).

### D. Tests — extend `backend/tests/Feature/SmsServiceTest.php`
- Log driver sends nothing to the network: `config()->set('services.semaphore.driver', 'log')`
  + empty key → `sendOtp()` succeeds, `Http::assertNothingSent()`, and a log line
  was written (spy `Log::channel('sms')` or assert `Log::hasRecorded`).
- `available()` is `true` in log mode with no key.
- `available()` is `false` in semaphore mode with no key; `true` with a key (existing tests keep).
- Log driver still rejects invalid phone numbers.

### E. Docs
- `README.md` env-vars table: add `SMS_DRIVER` row; amend the `SEMAPHORE_*` rows
  to mention the log fallback (one-line, mirroring the MAIL_MAILER=log wording).
- `docs/deployment-runbook.md` §3 `.env` checklist: add `SMS_DRIVER=semaphore` +
  the key (prod must not default to log).
- `docs/insights/product-decisions.md`: new dated entry in §51 area — "Semaphore has
  no sandbox → dev/test uses a log driver (same pattern as MAIL_MAILER=log); Twilio
  trial was considered and rejected — its sandbox would force a second provider for
  a niche flow, and portal SMS usage drops sharply after launch".
- `docs/insights/implementation-notes.md` Notifications §SMS: add the driver section.
- `docs/summary/2026-08-18-sms-notifications.md`: append this sandbox work
  (or add a short addendum block) with results.

## Validation (answers "how do I test it")

**Dev, no Semaphore account:**
1. `php artisan sms:test 09171234567` → prints the log-hint; `storage/logs/sms.log`
   contains `phone`, `code`, and the interpolated message. Nothing hit the network.
2. Portal flow: log in as `test@example.com` → Settings → add a phone (any valid
   PH format) → Save → Change password → choose **SMS** → "Send verification code"
   → read the 6-digit code from `storage/logs/sms.log` → enter it → password
   changes. Resend + incorrect-code/5-attempts still behave as before.
3. Forgot-password → "Send by SMS instead" → code in the log → reset succeeds.
4. `GET /api/health/sms` → `{available: true, hasPhone: true}` (after saving phone).
5. Semaphore mode guard: with `SMS_DRIVER=semaphore` and no key, `sms:test` fails
   with the key-missing error (`available()` false) — proves the prod default can't
   silently log.

**Production (go-live, manual):**
6. Semaphore signup/setup (§Manual steps above), then `SMS_DRIVER=semaphore` + key →
   `php artisan sms:test <your-number>` → real SMS arrives (~2 credits). Settings
   SMS flow to your own number once, then hand off.

**Automated:** touched files via
`php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter=SmsServiceTest` ;
frontend unchanged by this amendment (no UI change) but re-run `npm test` if
comfortable. `php -l` on the two changed PHP files.