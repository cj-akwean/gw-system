# 2026-08-09 — Auth-aware nav (profile dropdown) + landing-page exits + mouse-loop fix

## Goal

Three frontend fixes from a single session:

1. Kill the repeated `Maximum update depth exceeded` console error on the landing
   page (root cause in the mouse-tracking hooks, not the loading screen).
2. Make it possible to get back to the landing page from anywhere (dashboard,
   onboarding, pay, auth) — brand links + dropdown Landing Page item + back link.
3. Make the hero nav auth-aware: replace the static "Sign In" button with a
   kokonutui-style profile dropdown once a session exists, and put the same
   dropdown in the portal headers (user-confirmed: items = Profile, Dashboard,
   Settings placeholder, Landing Page, Sign Out; brand wordmark must match the
   landing page's amber-dot version).

## Files created

- `frontend/src/lib/avatars.tsx` — `AVATARS` + `AVATAR_RGB` + `getAvatar()` moved
  out of the avatar picker (single source for picker + dropdown).
- `frontend/src/components/ui/dropdown-menu.tsx` — shadcn-style Radix dropdown
  written against the unified `radix-ui` package (project convention, cf.
  `info-tip.tsx`/`button.tsx`); Trigger/Content/Item/Separator/Group only.
- `frontend/src/components/portal/profile-dropdown.tsx` — trigger (name/email +
  avatar SVG w/ per-avatar color ring, text collapses on mobile) + menu:
  Profile (`/onboarding`) · Dashboard (`/dashboard`) · Settings (`#` placeholder,
  easy to wire later) · Landing Page (`/`) · Sign Out (destructive, calls
  `onLogout`).
- Tests: `profile-dropdown.test.tsx` (4), `dashboard-header.test.tsx` (2),
  `ui/hero-33.test.tsx` (3).

## Files modified

- `frontend/src/hooks/use-mouse-position.ts` — rAF-throttled updates, identical
  coords bail out via functional setState; `touchmove` guards missing touch.
- `frontend/src/hooks/use-dimensions.ts` — same-value bailout.
- `frontend/src/hooks/use-elastic-line-events.ts` — effect deps are primitives
  (`x/y/width/height`) instead of object identities; `setControlPoint` /
  `setIsGrabbed` use functional updates that return `prev` when unchanged.
- `frontend/src/components/kokonutui/avatar-picker.tsx` — imports avatars from
  `@/lib/avatars` instead of its local copies (rest of the file untouched).
- `frontend/src/components/ui/hero-33.tsx` — nav right side: skeleton pill while
  `!ready`, `ProfileDropdown` when authenticated, WaterCanvas "Sign In" otherwise;
  sign-out → `logout()` + `router.push("/")`.
- `frontend/src/components/portal/dashboard-header.tsx` — brand is a
  `Link href="/dashboard"` with the landing's `Guinobatan Waterworks` + amber
  dot; right side is `ProfileDropdown` (props changed to `user: PortalUser | null`
  + `onLogout`).
- `frontend/src/app/dashboard/page.tsx`, `frontend/src/app/onboarding/page.tsx` —
  header prop swap; `loggingOut` flag so the /auth guard doesn't fire during a
  sign-out; `handleLogout` → `logout()` + `push("/")`.
- `frontend/src/components/portal/payment-method.tsx` — same header prop swap +
  header sign-out → `/` (session-expiry auto-logout keeps `/auth`).
- `frontend/src/app/auth/page.tsx` — "← Back to home" link under the card.
- Tests: `dashboard/page.test.tsx`, `onboarding/page.test.tsx` header mocks now
  take `user` instead of `userName`/`userEmail`.
- `ARCHITECTURE.md` (portal checklist item), `docs/insights/product-decisions.md`
  (§38 dropdown/avatars, §39 mouse-loop root cause).

## Bugs found & fixed (with root cause)

- **"Maximum update depth exceeded" at `useMousePosition` `setPosition`:** every
  mousemove created a fresh position object → `useElasticLineEvents` effect
  re-ran (object dep) and unconditionally stored a fresh `controlPoint` →
  render churn that tripped React 19 dev's passive-nested-update guard (verified
  the exact message in `react-dom-client.development.js`'s
  `nestedPassiveUpdateCount` branch). Fixed by rAF-throttle + identical-value
  bailouts + primitive deps. Same visuals, bounded work per event.
- **Type error in build:** a third `DashboardHeader` caller existed —
  `payment-method.tsx` (pay screen) still passed `userName`/`userEmail`.
- **Lint warning on my own file:** unused `isOpen` state in `profile-dropdown`
  (the kokonutui bending indicator was dropped) — removed.
- Lint baseline unchanged: 8 pre-existing errors (incl. the same
  set-state-in-effect error that was already in the old
  `use-elastic-line-events.ts`), 9 warnings — nothing new introduced.

## Test results

- Frontend: **157/157** (`npm test`; +9 tests). Build: `npm run build` static,
  all 5 routes generated, TS clean. Lint: baseline only.
- `next dev --turbo` smoke: `/` and `/auth` compile + serve 200 with no compile
  errors. Browser-mouse-move check of the removed console error was NOT
  manually verified (needs a real browser session).

## Known gaps / next step

- Profile dropdown "Profile" item routes to `/onboarding`, which resumes at the
  link/done step for complete profiles (avatar step only for avatar-less users).
  A `?step=profile` deep link is the natural follow-up if editing profile from
  the dropdown matters.
- Settings item is a placeholder (`#`).
- Not manually verified in browser: dropdown on dashboard header, sign-out →
  landing, hero dropdown on mobile (avatar-only trigger).

## Git state

NOT committed (per project rules).
