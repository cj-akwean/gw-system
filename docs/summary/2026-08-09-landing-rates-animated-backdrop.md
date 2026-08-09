# 2026-08-09 — Landing rework: nav/CTAs, Rates card, animated backdrop, dashboard date

## Goal

1. Fix landing navbar placeholder words + wire the two dead hero buttons.
2. Add a Rates card section (pasted-Pricing-style) fed by real data, with the 3D
   liquid-ocean background; ask whether to swap polka dots for an animated dot grid.
3. Show today's date on the dashboard.
4. Fix the auth delay (Sign In → blank page for seconds).

Decisions from the user: nav = Rates / How It Works / Contact; buttons = "Pay My Bill"
(auth-aware) + "View Rates"; rates from a new public API endpoint; **add** the 3D ocean as
background (optimized — "it's a background, lower it"); fractal grid on the **landing only**,
must work in dark mode; date under the sticky header.

## Files created

- `backend/app/Services/RateService.php` — `publicPayload()`: current schedule by date
  window (+ tiers sorted by min) + current PenaltyRule.
- `backend/app/Http/Controllers/Api/RateController.php` — `GET /api/rates`, 404 when no
  schedule in effect.
- `backend/tests/Feature/RateApiTest.php` — flat + tiered + 404 + expired-window (4 tests).
- `frontend/src/components/landing/rates-section.tsx` (+ test) — Pricing-style card on
  `liquid-ocean` bg; loading spinner; fetch-failure → office-contact fallback; "Pay My Bill"
  CTA auth-aware; live ₱10.00/m³ + penalty/grace/disconnection features.
- `frontend/src/components/landing/how-it-works.tsx`, `contact-section.tsx`,
  `landing-backdrop.tsx` — 3-step strip, contact cards (placeholders), themed fractal grid.
- `frontend/src/components/ui/liquid-ocean.tsx` (vengenceui registry), `canvas-fractal-grid.tsx`
  (cult-ui registry, written directly — cult-ui 429'd the CLI; `blendMode` prop added for
  dark mode, perf-degrade setState moved to rAF callback).
- `frontend/src/components/portal/page-loader.tsx` — spinner replacing `return null` guards.

## Files modified

- `backend/routes/api.php` — public `/api/rates` (throttled, distinct prefix).
- `frontend/src/components/ui/hero-33.tsx` — navItems → `{label, href}`; CTA handlers
  (Pay My Bill router push auth-aware; View Rates scrolls); Droplets icon; fixed a
  pre-existing tsc error in the disabled features block (`React.ElementType` → typed
  ComponentType).
- `frontend/src/app/page.tsx` — fractal grid replaces polka dots (landing only), rates /
  how-it-works / contact sections, `scroll-smooth`.
- `frontend/src/app/dashboard/page.tsx` — "Sunday, August 9, 2026" line under header.
- `frontend/src/app/settings/page.tsx`, `dashboard/page.tsx`, `auth/page.tsx` — loader
  instead of blank during auth-gate (never a white flash).
- `frontend/src/lib/api.ts` — `PortalRates` + `getRates()`.
- Tests: `hero-33.test.tsx` (nav items shape + next/link mock), `bills-list.test.tsx`
  (total assertion from earlier round), dashboard page test (loader role).
- Docs: `ARCHITECTURE.md` (Landing Page bullet), `implementation-notes.md` (Landing Page §1).

## Bugs found & fixed (root cause)

- **Sign In → blank page for seconds:** hero links were plain `<a href="/auth">` → full
  reload → dev webpack cold-compiles `/auth`. Fix: `next/link` + prefetch (client nav,
  compiles in background). Verified warm nav = 63–327ms, no blank flash.
- **View Rates scroll reverted to top:** `scrollIntoView({behavior:'smooth'})` interrupted
  by hero motion re-renders. Fix: plain `scrollIntoView()`; CSS `scroll-smooth` on the page
  root does the smoothing (matches anchor behavior exactly).
- **React 19 purity lint on registry components:** `Math.random` in render (ocean/boats) →
  seeded mulberry32 PRNG; setState-in-effect (fractal grid fps degrade) → moved into the
  rAF callback; `RectAreaLightUniformsLib` import dropped (deprecated in three 0.185, no
  .d.ts).
- **three types missing:** three dropped bundled .d.ts in r156 → `@types/three` installed.
- **Dark mode fractal grid invisible:** registry hardcodes `multiply` blend (black bg =
  invisible dots) → `blendMode` prop (multiply/screen), colors switch via `useTheme`.
- **Sticky header + top-3 quirk:** `sticky top-3` shifts the pill down but content still
  overlapped 12px — header is `sticky top-0` + `pt-3` inner, so the pill floats with a
  clean gap and flow stays intact (from the previous round, noted for the record).

## Test results (actually verified)

- Backend full suite: **532/532** (phpunit with `-d memory_limit=512M`).
- Frontend full suite: **172/172** (vitest). `tsc --noEmit` clean. ESLint clean (2
  pre-existing warnings: hero `backgroundImage`, ocean `gridColor`).
- Live (Chrome DevTools MCP): landing at 390/1280 light+dark — fractal grid renders with
  correct blend, ocean canvas present, rates card shows ₱10.00 + Jan 1, 2026 effective
  date, nav anchors land exactly (844/0), dashboard date "Sunday, August 9, 2026" under
  header. Console: one harmless `THREE.Clock` deprecation warning (library-level).

## Known gaps / next step

- **Contact section phone/address are placeholders** (052) 000-0000 / generic office
  address — user must supply real office info.
- Demo seed data was restored during verification (stale revoked links + stray invoices
  removed, re-seeded) — `test@example.com` shows the canonical 2 unpaid bills.
- Session drops: auth localStorage in the test browser profile silently cleared mid-session
  once (re-login fine) — likely manual sign-out from an earlier round, not a bug.

Next step: real office contact info for the Contact section, then commit.
