# Session Summary — 2026-08-01 (Auth error handling)

## Goal
Fix the generic "These credentials do not match our records." login failure message so it is handled correctly on **both** sites: the Filament admin panel and the Next.js customer portal.

## Root cause
- The message is Filament's default (`filament-panels::auth/pages/login.messages.failed`), thrown from `Filament\Auth\Pages\Login::authenticate()` (v5.7.3) for **two different cases**: wrong credentials (line ~102) AND valid credentials but `canAccessPanel()` false (line ~159, via `attemptWhen`). A customer email on `/admin` was told their password was wrong when it wasn't.
- Customer API already had a custom message but the frontend leaked raw network errors (`Failed to fetch`) and there was no rate limit on `/api/login`.

## Files created
| # | File | Purpose |
|---|---|---|
| 1 | `backend/app/Filament/Auth/Login.php` | Custom admin login page: copy of v5.7.3 `authenticate()` with split failure messages; `throwFailureValidationException(string $message = ...)` override (optional param keeps signature compatible with parent) |
| 2 | `backend/tests/Feature/CustomerAuthTest.php` | wrong-pw 401 msg, unknown-email same msg, success token, throttle 429 (REMOTE_ADDR isolated bucket) |
| 3 | `backend/tests/Feature/AdminLoginPageTest.php` | Livewire: wrong pw + unknown email → "Incorrect email or password."; non-admin valid pw → "This account does not have access to the admin panel."; admin → no form errors |

## Files modified
- `backend/app/Providers/Filament/AdminPanelProvider.php` — `->login(Login::class)` (custom page)
- `backend/app/Http/Controllers/AuthController.php` — 401 message → "Incorrect email or password."
- `backend/routes/api.php` — `/api/login` gains `throttle:10,1` (admin already had Filament's 5/min)
- `backend/tests/Feature/AuthTest.php` — updated existing assertion to the new message
- `frontend/src/lib/api.ts` — `loginApi()` catches fetch failures → "Unable to reach the server. Please try again."
- `ARCHITECTURE.md` — new checked Auth checklist item
- `docs/insights/product-decisions.md` — §10 (message strategy + enumeration rationale + where-filter correction)

## Key discovery (factual correction)
The `admins` provider's `'where' => ['is_admin' => true]` in `config/auth.php` is **dead config** — Laravel 13's `EloquentUserProvider` constructor only takes hasher + model (`CreatesUserProviders::createEloquentProvider` ignores `where`). Admin gating actually works via `FilamentUser::canAccessPanel()` (login page `attemptWhen` + `Authenticate` middleware 403). Behavior is correct; the config is misleading. Documented in product-decisions §10; left in place (removing is cosmetic).

## Test results
- New tests: 8/8 pass (4 customer API + 4 admin Livewire)
- Full suite: **33/33 pass** (was 25 before this session; 1 existing test updated, not weakened)
- Frontend `npm run lint`: 17 problems, ALL pre-existing in unrelated files (dotmatrix/loading/animation physics) — none in touched files
- Route list verified: `admin/login` → `App\Filament\Auth\Login`; `api/login` → `ThrottleRequests:10,1`

## NOT browser-tested (needs user's manual pass)
- Admin `/admin/login`: wrong password message, non-admin email message ("does not have access")
- Customer `/auth` page: wrong password message, killed-server network error text
- Throttle behavior (10 rapid attempts → 429)

## Known gaps / next step
- Custom `authenticate()` is a vendor copy — re-diff against `Filament\Auth\Pages\Login` on Filament upgrades (noted in the file + product-decisions §10)
- Next step per checklist: Billing phase (reading gaps noted in previous summaries), or any unchecked ARCHITECTURE.md item.

## Git state
Changes uncommitted. Not committed — waiting for user's manual verification + go-ahead.

## Addendum — seeded test credentials (same session)
User asked for the password of `test@example.com`. It is seeded in
`backend/database/seeders/DatabaseSeeder.php:26-30` with **no explicit password**, so it
falls back to the `UserFactory` default (`UserFactory.php:38`): **`password`**.

| Email | Password | Role | Works on |
|---|---|---|---|
| `admin@gwsystem.com` | `admin123` | Super Admin | `/admin` (Filament) |
| `test@example.com` | `password` | Customer (non-admin) | Customer portal (`/auth`), NOT `/admin` |

Note: `test@example.com` is exactly the case the new admin login test covers — valid
credentials but `canAccessPanel()` false → "This account does not have access to the
admin panel."
