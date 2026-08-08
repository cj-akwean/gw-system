# 2026-08-08 — Portal self-registration (email+password) + profile onboarding wizard

## Goal

Replace the "Contact admin to create an account" stub with real self-service signup
(email + password only), then guide fresh accounts through a profile onboarding wizard
(avatar + username, then meter linking — skippable) before landing on the dashboard.

## Decisions (user-confirmed up front)

1. Link step is **skippable** — dashboard shows a "Link your meter" prompt until linked.
2. Username **reuses `users.name`** (non-unique display name), no new column.
3. **One active link per connection** — 409 if actively linked to another user.

## Files created / modified

Backend:
- `database/migrations/2026_08_08_000003_add_avatar_id_to_users_table.php` — `name` →
  nullable, `avatar_id` nullable tinyint.
- `app/Http/Requests/Auth/RegisterRequest.php` — email unique + password min-8 confirmed.
- `app/Http/Requests/UpdateProfileRequest.php` — name 3–20, avatar_id 1–4.
- `app/Http/Controllers/AuthController.php` — `register()` (auto-login response;
  login user payload gains `avatar_id`).
- `app/Http/Controllers/Api/ProfileController.php` (NEW) — `PATCH /api/profile`.
- `app/Http/Controllers/Api/ConnectionLinkController.php` — friendly 404 message;
  **one-active-link guard** via transaction + `pg_advisory_xact_lock` (abort 409);
  `avatar_id` added to `User` fillable.
- `routes/api.php` — `POST /register` (guest, `throttle:10,1,auth-register`),
  `PATCH /profile` (auth, `throttle:30,1,profile-update`).
- Tests: `RegisterApiTest` (6), `ProfileUpdateApiTest` (5), `ConnectionLinkApiTest` +4.

Frontend:
- `src/app/onboarding/page.tsx` (NEW) — 3-step wizard: ProfileSetup → Link meter
  (skip exit) → all-set; resumable via `/user` + `/links`; mobile-first, rail in left
  column at lg.
- `src/components/onboarding-06.tsx` — adapted from registry: parameterized steps,
  lucide `Check` (tabler removed), no demo chrome.
- `src/components/kokonutui/avatar-picker.tsx` — installed via `npx shadcn@latest add
  @kokonutui/avatar-picker` (as-shipped, default export `ProfileSetup`).
- `src/components/ui/card.tsx`, `input.tsx` — added by the shadcn install.
- `src/components/portal/link-meter-prompt.tsx` (NEW) — dashboard prompt card.
- `src/lib/api.ts` — `PortalUser` (name/avatar_id nullable), `registerApi`,
  `updateProfileApi`, `getLinks`, `createLink`.
- `src/lib/auth-context.tsx` — `signup()`, `updateProfile()` (refreshes localStorage
  session copy), `User` → `PortalUser`.
- `src/components/auth.tsx` — signup face now email+password only (name + confirm
  removed), wired to `signup()`.
- `src/app/auth/page.tsx` — post-auth redirect: avatar null → `/onboarding`.
- `src/app/dashboard/page.tsx` — `<LinkMeterPrompt />` above bills.
- `src/components/portal/dashboard-header.tsx` — `userName?: string | null`.
- `package.json` — `@tabler/icons-react` installed by shadcn, **uninstalled again**
  (code swapped to lucide; no unused deps left).
- Tests: `app/onboarding/page.test.tsx` (11), `components/auth.test.tsx` (4),
  `link-meter-prompt.test.tsx` (4), `api.test.ts` +9; dashboard page test mocks the
  new prompt.

## Bugs found & fixed (with root cause)

- **Wizard tests stalled at the avatar step:** the page's API calls go through
  `authFetch`, which throws 401 "Session expired" when no localStorage token is
  seeded — the GET /links effect then degraded to the fallback step and POST /links
  showed an error. Fix: tests seed `{token}` before render (the page itself is fine —
  the real app always has a session on that route).
- **Duplicate step-rail text broke `getByText`:** the rail renders twice (desktop left
  column + mobile top, CSS-hidden) — tests now use `getAllByText`.
- **"You're all set" matching:** heading is "You're all set, {name}!" vs rail title
  "You're all set" — exact/regex matchers tuned (`/You're all set.*!/`).
- **Build type errors:** `user.name` became `string | null` → `DashboardHeader` and
  `AllSetStep` props widened to accept null (component already handled falsy).
- **Lint `react/no-unescaped-entities`:** apostrophes in "You're all set" /
  "I'll do this later" → `&apos;` entities.

## Test results

- Backend: **526/526, 2,314 assertions** (`php -d memory_limit=512M
  vendor/phpunit/phpunit/phpunit`; +15 tests). Dev DB migrated (avatar migration).
- Frontend: **148/148** (`npm test`); `npm run build` static, `/onboarding` generated.
- Lint: **baseline only (8 pre-existing errors)** — no new issues in touched files.

## Known gaps / next step

- NOT manually verified in the browser yet: signup → wizard → skip → dashboard prompt
  → link → bills; duplicate-email; wrong combo; second-user 409; login resume at the
  right step. Also worth checking: existing `test@example.com` (name already set, no
  avatar_id) now lands on `/onboarding` after login — the avatar step shows with the
  name field empty; acceptable, but if admin-seeded users should skip onboarding,
  that's a decision for later.
- Deferred: email verification, unique username column, multi-connection management UI,
  PayMongo customer at signup.
- Next step: live browser pass of the full journey (and `graphify . --update` — new
  controller + routes + page).

## Git state

NOT committed (per project rules).
