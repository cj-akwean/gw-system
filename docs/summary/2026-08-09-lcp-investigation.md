# 2026-08-09 — LCP investigation, orphaned dev servers, hamburger water fix (round 5)

## Goal

The user reported LCP 14.24s on `/` and "the site got more laggy". Investigation
found the primary cause was environmental — 7 leftover `node.exe` processes
(~1.8GB, spiking toward 5GB during hot reloads) from stopped/dev servers, with
nothing even listening on 3000-3010. The LCP measurement was taken while the
machine was thrashing. Also fixed: the hamburger water button overflowed the
menu (the WaterCanvas + anchor were `w-full` inside the dropdown, so the water
sim spanned the whole menu instead of the button).

## Root causes

- **Orphaned dev servers:** my earlier smoke tests killed the `cmd.exe` wrapper
  with `Stop-Process` without killing the node child trees. On Windows, killing
  the parent doesn't kill the children → every dev attempt left a full
  Next.js process tree behind. Combined with the user's own stopped server,
  that's 7 node processes.
- **Hamburger overflow:** `WaterCanvas className="w-full"` + `flex w-full`
  anchor inside the dropdown → the water canvas (absolute inset-0 overlay)
  covered the entire menu width.
- **LCP leverage:** the WebGL `Ripple`'s html-capture (`layoutsubtree`
  `drawElementImage`) ran from the first paint on the landing page — during the
  whole loader window — plus no preload/fetchpriority on the LCP image.

## Changes

- Killed all leftover node processes; one clean `npm run dev` started for
  verification (process tree tracked; taskkill /T for future kills).
- `hero-33.tsx` — hamburger water button is now a compact centered pill
  (`flex justify-center p-1`, no `w-full`, `rounded={6}` matches the button);
  water fills only the button.
- `hero-33.tsx` — hero img gets `fetchPriority="high"`.
- `layout.tsx` — `<link rel="preload" as="image" href="/images/water-orb.webp">`
  in head (129KB, cached) so the LCP image starts fetching immediately.
- `opening-animation.tsx` — **Ripple now mounts only when `loadingComplete`
  flips** (user-confirmed fallback): zero page-capture cost during the
  loader/LCP window. The splash is preserved via a pending-splash ref:
  `handleLoadingDone` stores strength/position, `handleRippleReady` fires it
  the instant the ripple mounts. `LoadingContext.Provider` moved outside the
  ripple so the hero's `loadingComplete` works either way. Same visible
  timeline (splash right after the loader).
- Docs: summary, ARCHITECTURE.md, product-decisions §43.

## Test results

- Frontend: **168/168** (`npm test`). Lint: baseline (8 errors, 9 warnings).
  Build: static, 6 routes. Clean dev server: `/` 200 (2.8s cold turbo
  compile), `/auth` 200 (477ms), webp 200 (132KB); exactly 4 node processes
  (one normal dev tree).
- **LCP re-measurement must be done by the user in the browser** — the
  previous 14.24s was measured under 5GB of process thrash.

## Known gaps / next step

- Browser re-check of LCP (expect well under 2.5s on the clean machine) and the
  hamburger water button look.
- If LCP is still poor, next lever would be shrinking the splash itself (or
  overlay-only waves on phones) — not applied, user kept full waves.

## Git state

NOT committed (per project rules).
