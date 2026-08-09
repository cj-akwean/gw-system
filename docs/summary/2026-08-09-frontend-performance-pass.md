# 2026-08-09 — Frontend performance pass + landing-only ripple (round 4)

## Goal

The frontend felt too heavy for phones. This round: keep the full landing page
experience but stop paying for it on portal pages, fix the hero image (the user
converted the 1.5MB PNG to a 129KB WebP — the page still referenced the deleted
PNG, so the hero image was 404ing), reduce the most expensive GPU work on
phones, and give the hamburger the water-filled Sign In button.

## Files modified

- `frontend/src/app/layout.tsx` — `<OpeningAnimation>` (LoadingScreen + WebGL
  Ripple + LoadingContext) removed; layout keeps only `<Providers>`.
- `frontend/src/app/page.tsx` — landing page now owns `<OpeningAnimation>`;
  glow layer uses `blur(var(--glow-blur))`.
- `frontend/src/components/ui/hero-33.tsx` — hero image → `/images/water-orb.webp`
  (1058×908, width/height attrs); **ElasticLine mounts only after
  `loadingComplete`** (startup rAF loops no longer compete with the loader);
  desktop Sign In WaterCanvas mount deferred the same way (`signInLink` const,
  wrapped when ready); **hamburger Sign In is now a WaterCanvas amber button**
  (full-width row — the sim mounts only while the menu is open).
- `frontend/src/app/globals.css` — `--glow-blur`: 32px mobile-first, 80px
  ≥768px; the 6 duplicated inline `blur(80px)` glow layers now use the var.
- `frontend/src/app/auth/page.tsx`, `dashboard/page.tsx`,
  `onboarding/page.tsx`, `settings/page.tsx`,
  `components/portal/payment-method.tsx` — glow blur var swap.
- `frontend/src/components/ui/hero-33.test.tsx` — webp src assertion; hamburger
  test updated (Sign In is now an anchor row, not a Radix menuitem).
- `ARCHITECTURE.md`, `docs/insights/product-decisions.md` (§42).

## Why (root causes)

- **Ripple ran on every page via layout:** the WebGL effect captures the whole
  page HTML into a canvas on every paint (`layoutsubtree` path) and runs a
  shader pass — on phones this is the single heaviest constant cost. Portal
  pages never needed it; only the landing's hero consumes `LoadingContext`.
  Now the loader + ripple + their code chunks belong to `/` alone — portal
  routes render instantly and don't download that JS.
- **Full-viewport `blur(80px)` on a fixed layer** (6 pages) forces a
  viewport-sized Gaussian on the phone GPU; the `--glow` gradient is already
  soft, so mobile drops to 32px via a CSS var (a one-line toggle if the blur
  is ever removed entirely).
- **The hero image 404'd:** `public/images/water-orb.png` was deleted when the
  WebP was added; the `<img>` still pointed at the PNG.
- **WaterCanvas/ElasticLine mounted behind the loader:** three rAF loops
  (loader dot-matrix, elastic line, water sim) all started at once on phones;
  both now mount when the loader finishes.

## Test results

- Frontend: **168/168** (`npm test`). Lint: baseline (8 errors, 9 warnings,
  none new). Build: static, 6 routes, TS clean. Dev smoke: `/`, `/auth`,
  `/images/water-orb.webp` all 200 on the running turbo server.

## Known gaps / next step

- Not verified in-browser: landing after the ripple move (splash timing,
  elastic-line draw now happens after the loader), hamburger water button feel,
  glow softness at 32px on phones.
- Bundle: portal routes no longer pull the sim code; the landing chunk is still
  heavy (motion + canvas sims) — acceptable, it's a one-screen showcase page.

## Git state

NOT committed (per project rules).
