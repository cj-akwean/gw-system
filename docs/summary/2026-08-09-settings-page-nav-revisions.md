# 2026-08-09 — Settings page + nav revisions (brand = landing, context-aware dropdown, square avatar)

## Goal

Revisions on top of the same-day auth-nav work (see `2026-08-09-frontend-auth-nav-mousefix.md`):

1. Brand wordmark ("Guinobatan Waterworks.") in every portal header → landing page `/`.
2. Dropdown: drop the "Landing Page" item; hide "Dashboard" while on `/dashboard`;
   Settings becomes a real page.
3. New `/settings`: edit profile + link/unlink meters.
4. Dropdown avatar becomes a rounded square (like the onboarding picker thumbnails —
   circle crop hid the SVG face).

## Files created

- `frontend/src/app/settings/page.tsx` — auth-guarded settings shell (same `--bg`/glow/
  dots + `DashboardHeader`); Profile section = kokonutui `ProfileSetup` (prefilled) →
  `updateProfile()` with saved/error feedback; My Meters section = link list (account ·
  meter, registered name · barangay) with two-step inline **Unlink** (confirm →
  `DELETE /api/links/{id}` → row removed; Cancel resets) + extracted `LinkMeterForm`.
- `frontend/src/components/portal/link-meter-form.tsx` — the onboarding link form moved
  out verbatim (props `onLinked`, optional `onSkip`); onboarding now imports it.
- `frontend/src/app/settings/page.test.tsx` — 4 tests (guard redirect; links listed +
  form/profile rendered; unlink confirm flow incl. DELETE URL assert; profile save →
  "Profile saved." + `updateProfile` called).

## Files modified

- `frontend/src/components/portal/dashboard-header.tsx` — brand `Link` now `href="/"`.
- `frontend/src/components/portal/profile-dropdown.tsx` — removed Landing Page item;
  `usePathname()` hides Dashboard when `=== "/dashboard"`; Settings → `/settings`;
  avatar container `rounded-full` → `rounded-xl` (ring matches), so the SVG avatar
  shows uncropped like the picker thumbnails.
- `frontend/src/lib/api.ts` — `unlinkApi(linkId)` → `DELETE /api/links/{id}` (backend
  `ConnectionLinkController::destroy` already existed, ownership-checked).
- `frontend/src/components/kokonutui/avatar-picker.tsx` — new optional props
  `initialUsername` / `initialAvatarId` (defaults keep onboarding behavior).
- `frontend/src/app/onboarding/page.tsx` — inline `LinkMeterStep` removed, uses
  `LinkMeterForm`; unused imports trimmed.
- Tests: `profile-dropdown.test.tsx` (no Landing Page; Dashboard hidden on
  `/dashboard`; Settings href `/settings`), `dashboard-header.test.tsx` (brand → `/`),
  `api.test.ts` (+1 unlink DELETE test).

## Bugs found & fixed (with root cause)

- **Settings page API calls 401'd in tests:** `getLinks` goes through `authFetch`,
  which throws "Session expired" with no localStorage token — tests now seed
  `{token}` before render (same fix the onboarding suite already used).
- **Lint warning on own file:** `linksError` was set but never rendered — now surfaced
  under the meters section (also better UX than silently showing "No meters linked").

## Test results

- Frontend: **164/164** (`npm test`; +7 vs prior session). Lint: baseline (8 errors,
  9 warnings, none new). Build: static, 6 routes incl. new `/settings`, TS clean.
- Dev smoke: `/` and `/settings` served 200 (hot-reloaded into the already-running
  turbo dev server).

## Known gaps / next step

- Profile dropdown "Profile" item still routes to `/onboarding`, which resumes at the
  link/done step for complete profiles — `/settings` is now the better edit surface,
  so consider pointing "Profile" there or adding `?step=profile` later.
- Unlink has no cross-device sync beyond the revoke (bills disappear immediately —
  intended, but a revoked-link notice on the dashboard could be friendlier).
- Not manually verified in browser: settings unlink on a real backend, dropdown
  square avatar, brand → landing from all four portal pages.

## Git state

NOT committed (per project rules).
