# Customer Portal + Admin — Password UX & Feedback Improvements

## Goal

Improve auth-related feedback across the customer portal (Next.js) and admin (Filament):

1. **Password change (portal Settings + admin EditProfile)**: after a successful change (email or SMS OTP), automatically sign the user out and force a fresh login — portal redirects to `/auth` with a "sign in with your new password" banner; admin redirects to the Filament login. Failures keep inline feedback. If SMS is chosen but the account has no phone, say so clearly before sending.
2. **Show/hide password toggle**: add to every password input across the entire website (portal login/signup, settings ×3, reset-password ×2; Filament already defaults to revealable — lock it in explicitly).
3. **Onboarding**: add the phone field to the profile step, marked "(optional)", persisted via `updateProfile`.
4. **Forgot Password**: replace the "Send by SMS instead" checkbox with an explicit Email/SMS segmented picker (Email default). The backend's silent email fallback and generic success message stay as-is — **phone/account state must never be revealed**.
5. **Login/Signup error placement**: render form-level errors (e.g. "Incorrect email or password.") ABOVE the email field, so they read as form-level failures rather than email-specific validation.

All decisions confirmed with the user (2026-08-18).

## Files (frontend)

### 1. `frontend/src/components/ui/input.tsx` — global password reveal toggle

- Add `"use client"` directive (component will now hold hook state; all current importers are already client components).
- When `type === "password"`, render a `relative` wrapper `<div>` around the input:
  - `<input>` keeps `data-slot="input"` and all passed props (`aria-label`, `autoComplete`, `required`, etc.); `type={visible ? "text" : "password"}`; base classes **plus** `pr-9`; the `className` prop applied to the **inner input** so `InputGroupInput` layout classes (`flex-1`, `border-0`, `ring-0`, pl/pr offsets) still land on the input.
  - Toggle button: `type="button"`, `absolute right-0 top-1/2 -translate-y-1/2`, ~`h-9 w-9`, `aria-label={visible ? "Hide password" : "Show password"}`, `aria-pressed={visible}`, `onMouseDown={(e) => e.preventDefault()}` so the input keeps focus, `onClick` flips local `useState`. Icons `Eye`/`EyeOff` from `lucide-react` with `aria-hidden`.
- Non-password types render exactly as today (no wrapper).
- Auto-covers: `settings/page.tsx` (3 fields), `reset-password/page.tsx` (2), `components/auth.tsx` login/signup password (via `InputGroupInput`), and all future password inputs.

### 2. `frontend/src/app/settings/page.tsx` — password change auto-logout + no-phone feedback

- **Success path** (`handlePasswordChange`, currently lines 149-166): after `changePasswordApi` resolves:
  1. `setLoggingOut(true)` — prevents the existing `/auth` redirect effect (`settings/page.tsx:63-67`) from `replace`-ing to the bare URL and dropping the query param;
  2. `await logout()` — revokes the current token server-side via `POST /api/logout` and clears local auth state;
  3. `router.push("/auth?notice=password_changed")`.
- Remove the `passwordSaved` state and the inline "Password updated." status block (`settings/page.tsx:302-306`).
- Failure path unchanged (existing inline `passwordError`). Add a test: failed change (422) does NOT logout or redirect.
- **No-phone guard** in `handleSendOtp` (before the API call): if `otpChannel === "sms"` and `!user?.phone`, set `passwordError("Add a phone number in the profile section to get codes by SMS.")` and return without calling the API. Keep the existing passive hint and backend 422 handling as backstops.
- Security card helper text (`settings/page.tsx:248-251`): replace "You'll stay signed in on this device; other sessions will be signed out." with "After your password is changed, you'll be signed out and asked to sign in again with your new password."

### 3. `frontend/src/app/auth/page.tsx` — "password changed" banner

- Wrap `AuthContent` in `<Suspense>` (same pattern as `dashboard/pay/page.tsx:1-31`); inside `AuthContent`, read `const searchParams = useSearchParams()` and detect `searchParams.get("notice") === "password_changed"`.
- Render the banner at **page level, above the `FlippingCard`** (NOT inside the 400×520 card, which is fixed-height and would risk overflow): a compact `role="status"` block, e.g. `text-primary`, reading "Password updated. Please sign in with your new password."
- Share the query key via a small exported constant (e.g. `AUTH_NOTICE_PASSWORD_CHANGED = "password_changed"` in `frontend/src/lib/auth-context.tsx`) so `settings/page.tsx` and `auth/page.tsx` can't drift.
- `components/auth.tsx` stays unchanged for the banner (no prop needed).

### 4. `frontend/src/components/auth.tsx` — error above the email field

- Move the `{error && <p ...>}` block (currently between password and submit, lines 107-109) to the **top of the `<form>`**, above the email `InputGroup`. Keep `role="alert"` and current styling (`text-red-500 text-center` or `text-destructive`).
- Applies to both login and signup modes (shared block).

### 5. `frontend/src/app/onboarding/page.tsx` — optional phone field

- Pass `withPhone` and `initialPhone={user?.phone ?? ""}` to the `ProfileSetup` at `onboarding/page.tsx:162-174`.
- Thread `phone` through the `onComplete` handler: `updateProfile(username, avatarId, phone)`.
- Optionally tweak the onboarding step description ("Pick an avatar and a display name") to mention the optional phone.
- `avatar-picker.tsx` already renders the field labeled "(optional)" when `withPhone` is set — no change needed there.

### 6. `frontend/src/app/forgot-password/page.tsx` — explicit channel choice

- Replace the `useSms` checkbox with `otpChannel: "email" | "sms"` (default `"email"`), mirroring the Settings segmented toggle (`settings/page.tsx:313-358`): `role="radiogroup"` / `role="radio"` buttons styled like Settings.
- When `smsAvailable` is false, render no picker (email-only flow).
- Helper copy (capability-level only — must NOT leak whether a specific account has a phone):
  - "SMS codes require a phone number saved on the account; otherwise the code is sent by email."
  - The generic success message ("If an account exists for that email, a verification code is on its way.") stays unchanged (anti-enumeration). Backend `ForgotPasswordController` untouched — silent email fallback stays.
- Update the intro text to mention the code can arrive by email or SMS.
- Submit unchanged: `sendPasswordResetOtp(email, otpChannel)` (existing API shape).

## Files (admin backend)

### 7. `backend/app/Providers/Filament/AdminPanelProvider.php`

- Add `->revealablePasswords()` to the panel chain (after `->passwordReset(...)`, ~line 38). Filament already defaults this to `true`; this locks the intent in.

### 8. `backend/app/Filament/Pages/EditProfile.php` — admin auto-logout after password change

- In the custom `save()` (currently `EditProfile.php:103-124`), after `parent::save()` succeeds AND a new password was set:
  - queue `PasswordChanged` email (already in `afterSave()` — keep it there),
  - `Filament::auth()->logout()`,
  - `request()->session()->invalidate(); request()->session()->regenerateToken();`,
  - `$this->redirect(filament()->getLoginUrl(), navigate: false)`.
- Why after `parent::save()`: the base `save()` (vendor `EditProfile.php:162-231`) sends a "profile updated" notification and, if a password was set, writes the session `password_hash_...` and issues its own redirect (base `getRedirectUrl()`); running our logout+redirect last lets the final Livewire redirect to the login URL win, and `navigate: false` forces a full page load (the panel is in SPA mode — a fresh load is required after session invalidation). If the base redirect still wins in practice, fall back to returning an `HttpResponseException(redirect(filament()->getLoginUrl()))` from `save()` (flag during implementation; verify behavior in the Livewire test).
- OTP validation before `parent::save()` stays as-is; a failed OTP/current-password still halts with the existing notification and never logs out.

## Tests

- `frontend/src/app/settings/page.test.tsx`:
  - `"changes the password with an OTP and shows a confirmation"` → rename/update: assert `mockLogout` called and `mockPush` called with `"/auth?notice=password_changed"`; remove the `"Password updated."` expectation (route the success path through the existing `seedFetch` mock).
  - Add: server error on submit (422) → `mockLogout` NOT called, `mockPush` NOT called, error text shown.
  - Add: SMS selected with a user who has no phone → clicking "Send verification code" shows the no-phone error and does NOT hit `/api/password/send-code`.
  - Existing toggle/hint tests stay valid.
- `frontend/src/app/forgot-password/page.test.tsx`:
  - Replace the checkbox tests with radio-group equivalents: default submit sends `channel: "email"`; clicking "SMS" radio sends `channel: "sms"`; picker hidden when `smsAvailable=false`; helper text present.
- `frontend/src/app/auth/page.test.tsx` (new, modeled on `dashboard/pay/page.test.tsx`): mock `useSearchParams`; with `notice=password_changed` the banner text renders; without it, absent.
- `frontend/src/components/auth.test.tsx`: existing queries unaffected by error-message relocation (verify `findByText`/`getByText` still match); optionally add a check that the error renders above the email input (e.g. within the same form before the email field via DOM order).
- Optional: small test for the reveal toggle (button switches input `type` between `password`/`text`) if a suitable harness exists; otherwise covered by page rendering.
- `backend/tests/Feature/AdminEditProfileTest.php`:
  - Update `test_password_change_updates_hash_and_queues_email` to also assert the page redirects to the login route after a successful password change (and that the guard is logged out), matching actual Filament/Livewire behavior (use `assertRedirect(route('filament.admin.auth.login'))` or equivalent demonstrated by the implementation).
  - Non-password saves (`test_admin_can_save_name_email_and_avatar`, etc.) must NOT logout/redirect — verify unchanged.
- Backend `AdminPanelAccessTest` / `AdminLoginPageTest`: no attribute assertions on reveal toggles — expected unaffected; run the filter to confirm.

## Validation

Frontend (`frontend/`):
- `npm test` (baseline ~211; updated/new tests all green).
- `npm run build` (static export clean — Suspense wrap satisfies `useSearchParams`).
- `npm run lint` — no new errors beyond the pre-existing baseline (12).

Backend (`backend/`):
- `php -l` on changed PHP files (`AdminPanelProvider.php`, `Filament/Pages/EditProfile.php`).
- `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --filter "AdminPanelAccessTest|AdminLoginPageTest|AdminEditProfileTest|AuthTest|CustomerAuthTest|ChangePasswordTest|ForgotPasswordApiTest"`.

Manual (UX-critical):
- Settings → change password via email AND SMS (SMS_DRIVER=log writes to `storage/logs/sms.log`) → verify auto-logout + `/auth?notice=password_changed` banner; verify wrong OTP / wrong current password still shows inline error without logout.
- Settings → SMS chosen with no phone → inline no-phone error, no API call.
- Reveal toggles on: login, signup, settings (3), reset-password (2), Filament admin login + edit-profile.
- Onboarding → phone field "(optional)", persists into Settings.
- Forgot Password → picker defaults Email; SMS keeps generic success message; helper copy does not leak account/phone state.
- Admin EditProfile → change password (email OTP) → redirected to admin login, old session dead; non-password save does not log out.
- Login/Signup → wrong credentials error appears above the email field.

## Docs (AGENTS.md workflow — significant task)

- `ARCHITECTURE.md`: update the relevant Implementation Status one-liners.
- `docs/insights/implementation-notes.md`: add details under the matching section (§N).
- `docs/insights/product-decisions.md`: append a dated section — the "why": (a) password change forces re-login so users *see* the change take effect; (b) reveal toggle lives in the base `Input` so every password field gets it without per-field work; (c) forgot-password mirrors Settings' explicit picker because the checkbox hid the email default; (d) login errors sit above the fields so they aren't misread as email-specific; (e) forgot-password stays deliberately opaque about phone/account state (anti-enumeration).
- `docs/summary/YYYY-MM-DD-*.md`: one session summary at session end (goal, files, test results, known gaps, commit hash).

## Risks / notes

- **Base `Input` wrapper**: `className` must stay on the inner `<input>`; verify visually in the InputGroup login/signup layout that the eye sits at the right end without overlapping the underline strip or the inline-start icon.
- **Redirect race (portal)**: set `loggingOut` before `logout()` so the `/auth` redirect effect can't `replace` away the `notice` param.
- **Filament save/redirect (admin)**: base `save()` issues its own redirect after the `afterSave` hook — do logout+redirect after `parent::save()` so the login redirect wins; confirm in the Livewire test. SPA mode requires `navigate: false` (full reload) after session invalidation.
- **No backend API changes** for the portal: the current-session token is revoked by the frontend `logout()` → `POST /api/logout`. Known minor edge: if that logout request fails, the token could remain valid server-side until expiry — out of scope per decisions.
- **Anti-enumeration**: forgot-password UI/backend must never confirm whether a phone number or account exists for the entered email.