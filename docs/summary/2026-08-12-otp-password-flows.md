# 2026-08-12 — Email-OTP for password changes + forgot password (admin & portal)

## Goal

User requests: (1) password changes on BOTH the admin profile and the customer portal
must require an email OTP before the change applies; (2) forgot-password on both
sites, also OTP-by-email, with the link on both login pages; (3) all customer-portal
UI mobile-friendly and all emails (both sites) mobile-friendly. (Uncommitted session:
OTP + forgot-password only — previous session's admin-settings batch was committed
before this.)

## Files created / modified

**Backend**
| File | Action | What |
|---|---|---|
| `app/Auth/OtpTokenRepository.php` + `OtpPasswordBrokerManager.php` | new | 6-digit reset tokens; custom broker manager |
| `app/Providers/AppServiceProvider.php` | modified | forces the framework's deferred `PasswordResetServiceProvider` eager, then rebinds `auth.password` to the OTP manager |
| `config/auth.php` | modified | reset token expiry 60 → 15 min |
| `app/Services/OtpService.php` | new | change-password OTPs (cache, 5-min, 5 attempts, single-use) |
| `app/Mail/PasswordResetOtp.php` + `PasswordChangeOtp.php` + 4 templates | new | mobile-friendly OTP emails (600px hybrid shell, mono OTP block) |
| `app/Filament/Auth/RequestPasswordReset.php` + `ResetPassword.php` | new | admin forgot-password OTP pages (reset at `/admin/password-reset/reset-code`; vendor `/reset` stays signed/dead) |
| `app/Filament/Pages/EditProfile.php` | modified | "Send verification code" action + OTP field + `save()` gate |
| `AdminPanelProvider.php` | modified | `->passwordReset(...)` + unsigned `reset-code` route |
| `app/Http/Controllers/Api/ForgotPasswordController.php` + `ResetPasswordController.php` + `ChangePasswordController::sendCode` + `ChangePasswordRequest` | new/modified | portal endpoints, `otp` required on `/api/password` |
| `routes/api.php` | modified | forgot/reset/send-code routes (guest/auth, throttled) |
| tests: `OtpServiceTest`, `ChangePasswordTest` (rewritten), `ForgotPasswordApiTest`, `AdminPasswordResetTest`, `AdminEditProfileTest` (extended) | new/modified | 45 tests |

**Frontend**
| File | Action | What |
|---|---|---|
| `src/lib/api.ts` + `api.test.ts` | modified | `changePasswordApi(..., otp)`, `sendPasswordChangeOtp`, `sendPasswordResetOtp`, `resetPasswordApi` |
| `src/app/settings/page.tsx` + `page.test.tsx` | modified | Security: Send-code step + OTP input + submit with code |
| `src/app/forgot-password/page.tsx` + `page.test.tsx` | new | guest-only request page (mobile-first) |
| `src/app/reset-password/page.tsx` + `page.test.tsx` | new | guest-only reset page (mobile-first) |
| `src/components/auth.tsx` | modified | "Forgot your password?" link on the login card |

## Bugs found & fixed

1. **Deferred provider clobbers the broker rebind** — the framework's
   `PasswordResetServiceProvider` is `DeferrableProvider`; it re-registers
   `auth.password` on first resolution AFTER any `boot()` rebind. Fix: force it eager
   in `register()` then rebind. (Tokens were still 64-char random — caught by tests.)
2. **Vendor reset route is `signed`** — GET `/admin/password-reset/reset` 403s without
   a signature; same-URI registration overwrites ours. Fix: separate unsigned slug
   `reset-code` + notification link.
3. **`#[Locked] $email` on the vendor ResetPassword** — client email updates threw
   `CannotUpdateLockedPropertyException`. Fix: redeclare the property unlocked.
4. **Broker treated `otp` as a users column** — `retrieveByCredentials` adds a WHERE
   per credential key → SQL error. Fix: strip `otp` in `getCredentialsFromFormData`.
5. **Form field without a backing property** — `otp` state was lost on the reset page
   (validation "required" despite fillForm). Fix: `public ?string $otp`.
6. **`Halt` escapes direct method calls in tests** — EditProfile `save()` now returns
   early instead of throwing.
7. **Query-builder `update()` bypasses the hashed cast** — restore scripts wrote
   plaintext passwords (`User::where(...)->update(['password' => ...])`); must use the
   model (`$u->password = ...; $u->save()`). Also bit the browser demo login.
8. **Sanctum guard caches the user across requests in one test** — token-id leak;
   `auth()->forgetGuards()` between requests.

## Test results

- Backend: **77/77** (OtpService 6, ChangePassword 12, ForgotPasswordApi 9,
  AdminPasswordReset 7, AdminEditProfile 11, + RateLimit/Profile/Auth/Register)
- Frontend: **52/52** (api, settings incl. OTP flow, forgot + reset pages); build green
- Browser-verified (mailer temporarily on `log` driver to read codes, restored after):
  admin request page → OTP email → reset page → password changed (hash verified,
  token consumed); admin profile send-code → OTP → save gated correctly; portal
  forgot → reset → login with new password; portal settings OTP change; both OTP
  emails at 390px — no overflow, fluid container, code readable. All test credentials
  restored (admin123 / password), demo jobs/tokens purged.

## Known gaps / next step

- Admin password-change OTP goes to the CURRENT email; a combined email+password edit
  verifies against the pre-edit address (current-password also gates it).
- Next step: commit this batch (uncommitted).

## Git commit hash

Not committed (user hasn't asked).
