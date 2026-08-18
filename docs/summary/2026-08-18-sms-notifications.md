# 2026-08-18 — SMS OTP delivery (Semaphore) as an optional channel

## Goal

Per `.kilo/plans/1787014905485-sms-notifications-semaphore.md` (treated as source of
truth): add SMS as a user-selectable delivery channel for the app's own verification
OTPs (password-change + password-reset). Email stays the default. Card 3DS OTPs are
explicitly out of scope — the bank sends those via the card network; the app never sees
them.

## Files created / modified

| File | Action | What |
|---|---|---|
| `backend/app/Services/SmsService.php` | new | Semaphore OTP client: `sendOtp()` (form POST to `/api/v4/otp` with our `code` + `{otp}` placeholder, phone normalized to `639…`), `available()` (gates on `SEMAPHORE_API_KEY`), `normalizePhone()` (accepts `09…`/`639…`/`+639…`, throws otherwise), `OTP_MESSAGE` const, PayMongo-style timeout/retry/log |
| `backend/app/Jobs/SendOtpSms.php` | new | `ShouldQueue`, `tries=3`, backoff `[10,30,60]`; `failed()` → `sms` log + `AdminNotifier` bell alert |
| `backend/app/Services/OtpService.php` | modified | `send($user, $purpose, $channel='email')` — sms requires phone (throws `InvalidArgumentException`), stores channel in cache payload, dispatches `SendOtpSms`; `verify()` unchanged/channel-agnostic |
| `backend/app/Http/Controllers/Api/ChangePasswordController.php` | modified | `sendCode` accepts optional `channel`; 422 "Add a phone number in Settings first." without phone; channel-aware response message |
| `backend/app/Http/Controllers/Api/ForgotPasswordController.php` | modified | accepts `channel`; SMS when account has a phone, else silent email fallback; generic anti-enumeration response unchanged |
| `backend/app/Http/Controllers/Api/ProfileController.php` + `UpdateProfileRequest` | modified | optional `phone` (nullable, max:20, loose PH regex), saved/trimmed/returned |
| `backend/app/Http/Controllers/AuthController.php` | modified | `phone` added to login/register user payloads |
| `backend/app/Http/Controllers/Api/HealthController.php` + `routes/api.php` | modified | `GET /api/health/sms` → `{available, hasPhone}`; public + `throttle:10,1,health-sms`; uses `$request->user('sanctum')` so guests get `available` only |
| `backend/config/services.php`, `logging.php`, `.env.example`, `phpunit.xml` | modified | `semaphore` block, `sms` log channel, env docs, test log path |
| `backend/tests/Feature/SmsServiceTest.php` | new | normalization, payload shape, Failed-status throw, HTTP-error throw, `available()` gating |
| `backend/tests/Feature/{OtpServiceTest,ChangePasswordTest,ForgotPasswordApiTest,ProfileUpdateApiTest}.php` | modified | SMS-path coverage incl. SMS token actually resetting the password, no-double-send fallbacks, phone save/clear/reject |
| `frontend/src/lib/api.ts` | modified | `PortalUser.phone`, `updateProfileApi(name, avatar, phone?)`, `sendPasswordChangeOtp(channel)`, `sendPasswordResetOtp(email, channel)`, `checkSmsHealth()` |
| `frontend/src/lib/auth-context.tsx` | modified | `updateProfile(name, avatarId, phone?)` threads phone through |
| `frontend/src/components/kokonutui/avatar-picker.tsx` | modified | opt-in `withPhone`/`initialPhone` phone field; `onComplete` carries `phone`; onboarding untouched |
| `frontend/src/app/settings/page.tsx` | modified | channel segmented toggle (hidden when SMS unavailable), phone hint when SMS chosen w/o phone, channel-aware helper text | 
| `frontend/src/app/forgot-password/page.tsx` | modified | "Send by SMS instead" checkbox (hidden when unavailable), passes channel |
| frontend tests (`api.test.ts`, `settings/page.test.tsx`, `forgot-password/page.test.tsx`) | modified | new request/response shapes + toggle/hint/visibility tests |
| `ARCHITECTURE.md`, `docs/insights/implementation-notes.md` (Notifications §10), `docs/insights/product-decisions.md` (§51), `README.md` | modified | workflow docs per AGENTS.md |

## Bugs found & fixed

1. **My own controller-edit bug (caught on review, not by a test):** the first
   `ChangePasswordController` SMS guard mixed `&&`/`||` without parens, so
   `trim($user?->phone ?? '') === ''` evaluated for the EMAIL channel too — every user
   without a phone would have gotten the 422 even when asking for email. Fixed with
   `$channel === 'sms' && (! is_string($user->phone) || trim($user->phone) === '')`.
2. **Broken edit in `frontend/src/lib/api.test.ts`:** an edit replacing the
   `describe("links api", ...)` opening accidentally deleted its `beforeEach`/`afterEach`
   and the first test's `it(...)` opener, leaving dangling test bodies. Caught by a
   follow-up read; restored the original block before running tests.
3. **Test-only:** settings test asserted a hint text that spans an `<a>` element —
   Testing Library's default text matcher treats that as split text. Matched the
   trailing "above to get codes by SMS" segment instead.

## Test results

- Backend (`php -d memory_limit=512M vendor/phpunit/phpunit/phpunit`):
  - Touched files filter (`SmsServiceTest|OtpServiceTest|ChangePasswordTest|ForgotPasswordApiTest|ProfileUpdateApiTest`): **54/54** green.
  - Broader batch (+AuthTest/CustomerAuthTest/RegisterApiTest/RateLimitTest/Health): **82/82** green (747 assertions).
  - `php -l` clean on all changed PHP files; `php artisan route:list --path=health` shows both `/api/health/payment` and `/api/health/sms`.
- Frontend: `npm test` **211/211** green (20 files); `npm run build` clean (TypeScript + static export, 11 routes). `npm run lint` error count unchanged from baseline (12 problems — all pre-existing, none in newly added lines).

## Known gaps / next step

- **Semaphore business setup is unverified** — no real API key/credits/sender name on
  this machine, so `SmsService` was tested only against `Http::fake`. The manual steps
  in the plan (signup → credits → sender name `GW-SYSTEM` → API key → `backend/.env`)
  still need to be done by the user, then Validation items 1–5 in the plan.
- Card 3DS OTPs remain permanently out of scope (product-decisions §51).
- Out of scope per plan: payment receipts/reminders via SMS, admin alerts via SMS,
  balance-check CLI/auto-refill, Twilio driver/abstraction, phone OTP-verify-on-save.
- `graphify . --update` pending per AGENTS.md rule 3.
- Not committed (user hasn't asked).

## Addendum (same day) — dev/test sandbox via a `log` SMS driver

Per the plan amendment: Semaphore has **no sandbox** — every real call sends a live
OTP SMS (2 credits) to a real PH number. Added `SMS_DRIVER` (`log` default outside
production, `semaphore` in production), mirroring `MAIL_MAILER=log`:

- `SmsService`: `driver()` + a `sendOtp()` branch — `log` logs phone/normalized number,
  code and interpolated message to `storage/logs/sms.log` and returns; `semaphore`
  keeps the real POST path (now with an explicit `apiKey()` guard that throws
  "SEMAPHORE_API_KEY is not configured" instead of posting an empty key). `available()`
  is true in log mode, so the whole portal flow is exercisable offline with zero cost.
- `php artisan sms:test {number?} {--code=}` — dev harness mirroring
  `paymongo:simulate-payment`; prints where the code went (log file vs real send).
  Verified: log driver writes the code + hint and hits nothing on the network;
  `SMS_DRIVER=semaphore` with no key fails with the key-missing error.
- Config default uses `env('APP_ENV')`, **not** `app()->environment()` — the latter
  throws "Target class [env] does not exist" inside a config file because Laravel binds
  `env` only *after* config files load (LoadConfiguration detects the environment at the
  end). Found by the first SmsServiceTest run (11 errors) after wiring the driver line.
- Docs: README `SMS_DRIVER` row + amended Semaphore rows, deployment-runbook §3 env
  checklist (`SMS_DRIVER=semaphore` required in prod), product-decisions §52,
  implementation-notes §10 driver note.

**Results:** `SmsServiceTest` 11/11 green; broader batch
(`SmsServiceTest|OtpServiceTest|ChangePasswordTest|ForgotPasswordApiTest|ProfileUpdateApiTest|AuthTest|CustomerAuthTest|RegisterApiTest|RateLimitTest|QueueWorkerTest`)
**88/88 green**; `php -l` clean on all amended PHP files. Frontend unchanged by the
amendment (no UI delta). Live real-SMS validation still pending a Semaphore account
(plan §Validation step 6).