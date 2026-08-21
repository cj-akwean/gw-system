# 2026-08-21 — Google "Sign in with Google" login (client-side GIS)

## Goal

Wire the dead Google button on the customer-portal login page into a real login, remove the
GitHub button, and document the manual Google Cloud key setup. Frontend is a static export,
so the flow is **Google Identity Services (GIS)** in the browser → ID token POSTed to the
Laravel API → tokeninfo verification → Sanctum token (same shape as `/api/login`).

## Files created / modified

| File | Action | What |
|---|---|---|
| `backend/database/migrations/2026_08_21_122857_add_google_id_to_users_table.php` | new | `users.google_id` (nullable, unique) + `password` → nullable. |
| `backend/app/Models/User.php` | modified | `google_id` added to `#[Fillable]`. |
| `backend/config/services.php` | modified | `services.google.client_id` ← `GOOGLE_CLIENT_ID`. |
| `backend/.env.example` | modified | `GOOGLE_CLIENT_ID=` with setup comment. |
| `backend/app/Http/Requests/Auth/GoogleAuthRequest.php` | new | `credential` required. |
| `backend/app/Http/Controllers/AuthController.php` | modified | `google()` — tokeninfo verify → `aud` + `email_verified` checks → find/create by `google_id` → email-exists → 409 → Sanctum token. |
| `backend/routes/api.php` | modified | `POST /api/auth/google`, guest + `throttle:10,1,auth-google`. |
| `backend/tests/Feature/GoogleAuthTest.php` | new | 8 tests (`Http::fake` tokeninfo): create-new, existing google_id login, email-conflict 409, invalid token / wrong audience / unverified → 401, missing credential → 422. |
| `frontend/src/lib/api.ts` | modified | `googleLoginApi(credential)`. |
| `frontend/src/lib/auth-context.tsx` | modified | `loginWithGoogle(credential)` (reuses `applySession`). |
| `frontend/src/components/google-signin-button.tsx` | new | Official GIS button: lazy script load, `initialize` + `renderButton`, `replaceChildren` StrictMode guard, popup `error_callback`, hidden when `NEXT_PUBLIC_GOOGLE_CLIENT_ID` unset. |
| `frontend/src/components/google-signin-button.test.tsx` | new | 2 tests (null render without client id; never loads script). |
| `frontend/src/components/auth.tsx` | modified | GitHub + custom Google outline buttons removed → `<GoogleSignInButton />` under the OR divider. |
| `frontend/src/components/auth.test.tsx` | modified | `useAuth` mock gained `loginWithGoogle`. |
| `frontend/src/components/google-icon.tsx`, `github-icon.tsx` | deleted | Unused after the button swap. |
| `frontend/src/types/payjs.d.ts` | modified | `Window.google.accounts` (GIS) ambient types added beside the Google Pay ones. |
| `frontend/.env.example` | modified | `NEXT_PUBLIC_GOOGLE_CLIENT_ID=`. |
| `ARCHITECTURE.md` | modified | Auth checklist item added with pointers. |
| `docs/insights/implementation-notes.md` | modified | Auth §5 (full implementation detail). |
| `docs/insights/product-decisions.md` | modified | §57 (client-side ID token / no secret; reject-on-email-match anti-takeover policy). |

## Bugs found & fixed

1. **`react/no-unescaped-entities` on my new button** ("Couldn't load…") — reworded to
   "Could not load…". (Lint also reports 5 **pre-existing** errors in untouched files —
   `auth-context.tsx:40`, `payment-method.tsx`, `otp-input.tsx`, `use-elastic-line-events.ts`,
   `reset-password/page.tsx` — repo lint was already red before this change.)
2. **`tsc --noEmit` shows only pre-existing test-file errors** (`forgot-password`/
   `dashboard-header`/`profile-dropdown` tests) — none in touched files.

## Test results

- Backend: `phpunit --filter "GoogleAuthTest|AuthTest"` → **15 passed, 59 assertions**.
- Frontend: `vitest run` on `auth.test.tsx`, `auth/page.test.tsx`, `google-signin-button.test.tsx`
  → **9 passed**.
- `eslint` on all touched frontend files → clean. `php artisan migrate` applied on dev Postgres.
- Route confirmed via `php artisan route:list --path=auth`.

## Known gaps / next step

- **Not yet verified end-to-end** (needs real Google creds): the GIS button rendering and the
  live tokeninfo call. Do the manual setup below, then click the button on `/auth`.
- **Manual key setup (user's ask):** Google Cloud Console → project →
  APIs & Services → **OAuth consent screen** (External; add your test email under Test users)
  → **Credentials → Create Credentials → OAuth Client ID → Web application** →
  Authorized JavaScript origins: `http://localhost:3000` → copy the Client ID
  (`….apps.googleusercontent.com`) into `frontend/.env.local` (`NEXT_PUBLIC_GOOGLE_CLIENT_ID`)
  and `backend/.env` (`GOOGLE_CLIENT_ID`). No redirect URI / Client Secret needed for this
  flow. Restart both dev servers. Consent screen stays "Testing" until you publish —
  only your added test users can sign in.
- Possible polish (not done, keep scope): `google.accounts.id.disableAutoSelect()` on logout.

## Git

No commit made (per project rules).