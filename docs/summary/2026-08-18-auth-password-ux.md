# 2026-08-18 — Auth password UX & feedback (portal + admin)

## Goal

Per `.kilo/plans/1787029327589-auth-password-ux-plan.md` (source of truth): improve
auth feedback — password change forces re-login (portal + admin), show/hide password
toggle on every password input, optional phone field on onboarding, explicit Email/SMS
picker on Forgot Password, and login/signup form errors above the email field.

## Files created / modified

| File | Action | What |
|---|---|---|
| `frontend/src/components/ui/input.tsx` | modified | Global reveal toggle for `type="password"`: relative wrapper (+ `flex-1` when `data-slot="input-group-control"`), `pr-9` input, eye/eye-off toggle. Toggle is a `<span role="button">` (Enter/Space + `onMouseDown` preventDefault), NOT a `<button>` — a real button inside a wrapping `<label>` is labelable and breaks `getByLabelText`. |
| `frontend/src/components/ui/input.test.tsx` | new | Reveal toggle: renders, toggles `password`↔`text`, keeps focus, absent for non-password. |
| `frontend/src/lib/auth-context.tsx` | modified | `AUTH_NOTICE_PASSWORD_CHANGED` shared constant. |
| `frontend/src/app/settings/page.tsx` | modified | Success path sets `loggingOut` → `await logout()` → `router.push("/auth?notice=password_changed")`; removed inline "Password updated." block; no-phone client guard before `sendPasswordChangeOtp` ("Add a phone number in the profile section to get codes by SMS."); helper text now says you'll be signed out. |
| `frontend/src/app/auth/page.tsx` | modified | `useSearchParams` read under `<Suspense>` (static-export requirement); `role="status"` banner above the `FlippingCard` when `notice=password_changed`. |
| `frontend/src/components/auth.tsx` | modified | Form error block (with `role="alert"`) moved above the email field. |
| `frontend/src/app/onboarding/page.tsx` | modified | `ProfileSetup` gets `withPhone` + `initialPhone`; `onComplete` threads phone into `updateProfile`. |
| `frontend/src/app/forgot-password/page.tsx` | modified | Checkbox → Email/SMS segmented radiogroup (Settings pattern), Email default, hidden when SMS unavailable; capability-level helper copy; intro mentions email or SMS; `&apos;` escaped. |
| `frontend/src/app/settings/page.test.tsx` | modified | Success test renamed — asserts `mockLogout` + `mockPush("/auth?notice=password_changed")`, no "Password updated."; added 422-no-logout test; added no-phone SMS-guard test (no `/api/password/send-code` call); auth-context mock now exports the constant. |
| `frontend/src/app/forgot-password/page.test.tsx` | modified | Checkbox tests → radio-group tests (email default, SMS radio, hidden when unavailable, helper text). |
| `frontend/src/app/auth/page.test.tsx` | new | Banner renders with `notice=password_changed`, absent otherwise / for other values. |
| `frontend/src/components/auth.test.tsx` | modified | Error-above-email DOM-order assertion added. |
| `frontend/src/app/onboarding/page.test.tsx` | modified | `updateProfile` asserted with 3rd arg `null`. |
| `frontend/src/components/kokonutui/avatar-picker.tsx` | modified | Comment updated (phone field comment said onboarding untouched). |
| `backend/app/Providers/Filament/AdminPanelProvider.php` | modified | `->revealablePasswords()` after `->passwordReset(...)`. |
| `backend/app/Filament/Pages/EditProfile.php` | modified | `save()` captures `$passwordWasChanged` before `parent::save()` (base nulls the field after saving), then on success: `Filament::auth()->logout()`, session invalidate + regenerate token (guarded by `request()->hasSession()`), redirect to `filament()->getLoginUrl()` with `navigate: false`. Email stays in `afterSave()`. |
| `backend/tests/Feature/AdminEditProfileTest.php` | modified | Success test asserts `assertRedirectToRoute('filament.admin.auth.login')` + `assertGuest(guard: 'admin')`; non-password save asserts `assertAuthenticated`. |
| `ARCHITECTURE.md`, `docs/insights/implementation-notes.md` (Auth §3–4, Notifications §10), `docs/insights/product-decisions.md` (§53) | modified | Workflow docs per AGENTS.md. |

## Bugs found & fixed

1. **The eye toggle broke every label-based test.** The first implementation used a real
   `<button>` inside the Input wrapper. The settings page wraps each field in a `<label>`,
   and HTML makes any `<button>` inside a label *labelable* — so Testing Library's
   `getByLabelText("Current password")` matched both the input and the eye button ("Found
   multiple elements"). Fixed by making the toggle a `<span role="button">` (spans are
   not labelable) with `tabIndex={0}` + Enter/Space handlers — semantics preserved, tests
   green, and the real `<button>`-in-`<label>` a11y quirk is avoided.
2. **`props["data-slot"]` failed the TypeScript build.** React 19's input props type has
   no `data-*` index signature. Fixed by casting to `Record<string, unknown>` for the
   `isGroupControl` probe.
3. **The settings test mock omitted the new `AUTH_NOTICE_PASSWORD_CHANGED` export** — the
   page imports it at module level from the mocked `@/lib/auth-context`, so the constant
   came back undefined and the success-path test failed on the missing redirect. Added the
   export to the mock.
4. **Success-test microtask race:** asserting `mockPush` synchronously after
   `waitFor(mockLogout called)` raced the `await logout()` continuation. Polled both
   assertions inside a single `waitFor`.
5. **Pre-existing lint (`react/no-unescaped-entities`) on the two lines I rewrote** — the
   forgot-password intro and the settings helper text both had a straight apostrophe.
   Fixed with `&apos;` while touching them (lint total 12 → 10, no new errors).

## Test results

- Backend (`php -l` on all changed PHP files clean; filtered suite
  `AdminPanelAccessTest|AdminLoginPageTest|AdminEditProfileTest|AuthTest|CustomerAuthTest|ChangePasswordTest|ForgotPasswordApiTest`):
  **55/55 green** (admin EditProfile suite alone 11/11 including the new
  logout+redirect assertions).
- Frontend: `npm test` **222/222 green** (22 files; baseline was 211 across 20 files —
  +2 new test files, +1 net new test, several rewritten). `npm run build` clean (TypeScript
  + static export, 11 routes — the Suspense wrap satisfied `useSearchParams`).
  `npm run lint` **10 problems** (was 12 pre-existing: the 2 fixed unescaped entities;
  remaining 10 are pre-existing and none are in newly added lines).

## Known gaps / next step

- Manual UX verification per plan §Manual remains for the recording: reveal toggles
  across all surfaces, settings auto-logout + banner via email AND SMS
  (`SMS_DRIVER=log`, code in `storage/logs/sms.log`), admin EditProfile redirect to
  login with old session dead, error-above-email visual check, onboarding phone
  persistence.
- `graphify . --update` pending per AGENTS.md rule 3 (shared `Input`, auth-context,
  EditProfile touched).
- Not committed (user hasn't asked). Commit hash: pending.

## Addendum (same day) — code-review fixes

Second-pass review (6 findings, 2 warnings + 4 suggestions; user chose "Fix all"):

1. **WARNING — admin rate-limit logout (`EditProfile.php:128`).** The vendor base
   `save()` returns early (non-throwing) on its two rate-limit checks, so the original
   post-save block logged the admin out even when nothing was saved (OTP consumed, no
   password change). Fixed by gating on `$passwordWasChanged && blank($this->data['password'])`
   — the base only nulls the password field after a committed save. New regression test
   `test_rate_limited_save_does_not_log_out_or_redirect` pre-exhausts both limiter keys
   (with `RateLimiter::hit` ×5; note `hit($key, $decaySeconds)` adds ONE attempt, the
   second arg is decay time — my first draft passed 5 as decay and the test silently
   succeeded the save), asserts no redirect / still authenticated / no `PasswordChanged`
   mail.
2. **WARNING — `/auth` Suspense scope.** Wrapping all of `AuthContent` in a Suspense
   boundary deferred the whole page at static build. Split the banner into
   `PasswordChangedNotice` (owns `useSearchParams`, wrapped in `<Suspense fallback={null}>`);
   `AuthContent` stays outside. **Correction while fixing:** the login form was never in
   the static HTML anyway (the page renders a spinner until the `ready` effect flips
   client-side), so the LCP claim was a false positive — the refactor is kept because it
   is structurally cleaner (nothing but the optional notice is deferred).
3. **SUGGESTION — duplicated Email/SMS picker.** Extracted shared
   `components/portal/otp-channel-picker.tsx` used by settings + forgot-password.
4. **SUGGESTION — no-phone message drift.** Settings guard + inline hint now derive from
   one `NO_PHONE_SMS_MESSAGE` constant (anchor-tagged via string split for the hint);
   guard wording unified to include "above".
5. **SUGGESTION — logout+redirect idiom.** Extracted `lib/use-logout-redirect.ts`
   (`useLogoutRedirect` hook) used by settings (both header logout and the password-change
   success path) and onboarding.
6. **Suggestion not in scope of the chosen option:** old Sanctum token surviving a failed
   post-change `POST /api/logout` — already documented as an accepted risk in the plan's
   Risks section.

Also fixed while refactoring: removed the now-unused `AUTH_NOTICE_PASSWORD_CHANGED`
import from `auth/page.tsx` (moved into the notice component) — lint back to the 10
pre-existing problems.

**Re-validated after fixes:** backend 56/56 (filtered suite; EditProfile 12/12 incl. new
test, 62 assertions), frontend 222/222, `npm run build` clean, lint 10 (pre-existing
only), `php -l` clean.