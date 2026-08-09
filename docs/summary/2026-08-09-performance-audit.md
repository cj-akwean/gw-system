# 2026-08-09 — Performance audit + 8 fixes (round 6)

## Goal

Systematic performance audit of the frontend. User reported "double load"
effect (content appears → disappears → reappears) and 7GB memory usage from
orphaned node processes.

## Root causes found

1. **Double-load flash (Ripple):** OpeningAnimation conditionally mounted/unmounted
   the Ripple wrapper based on `rippleMounted` state. When it flipped true,
   children's DOM parent changed from a fragment to a canvas-wrapper or
   div-wrapper → layout shift → flash. Additionally, the Ripple's `failed`
   state (when WebGL worked but `createRipple()` returned null) caused a
   second re-render → children moved again → double flash.

2. **Double-load flash (WaterCanvas):** `{loadingComplete ? <WaterCanvas>{link}</WaterCanvas> : link}`
   — when loadingComplete flipped, the Sign In `<a>` went from being a direct
   child to being inside WaterCanvas's wrapper div + canvas overlay → another
   DOM restructure → flash.

3. **7GB memory:** 7 orphaned `node.exe` processes from dev-server smoke tests
   that killed the `cmd.exe` wrapper without killing child trees (Windows:
   parent kill ≠ child kill). The dev server itself also accumulated memory
   via Turbopack's in-memory cache + WebGL context re-init on every HMR.

4. **Continuous rAF loops:** 4+ concurrent `requestAnimationFrame` loops on
   the landing page (2 WaterCanvas, 1 Ripple, 1 ElasticLine) + 2 × 300ms
   `setInterval` for pixel-scale probing. None used a shared scheduler.

5. **Ripple page capture every frame:** When `htmlInCanvas=true`, the Ripple
   called `drawElementImage` on every paint to serialize the entire DOM into a
   canvas texture → O(DOM size) per frame + GPU texture upload every frame.

6. **Auth context value recreated every render:** `value={{ user, token, ... }}`
   created a new object reference every render → every `useAuth()` consumer
   re-rendered → cascading re-renders down the tree.

7. **ElasticLine rAF always running:** `useAnimationFrame` ran on every frame
   even when the line wasn't grabbed and the initial animation had completed.

8. **Plain `<img>` for LCP:** Hero image used a plain `<img>` tag instead of
   `next/image` with `priority`.

## Changes (8 of 10 findings fixed)

- **Ripple.tsx:** Added `active` prop. Always render the same DOM structure
  (canvas-wrapper or div-wrapper depending on native support). Effect only
  runs when `active=true`. Removed `failed` state and `supported` detection
  from inside the component — native support is now pre-detected in
  OpeningAnimation.

- **opening-animation.tsx:** Pre-detects `supportsHtmlInCanvas()` in a
  `useEffect` → stores in `native` state. Always renders the Ripple wrapper
  (when native) or plain children (when not). Uses `active={loadingComplete}`
  to control when the effect starts. Splash preserved via pending-splash ref.

- **water-button.tsx:** Added `frozen` prop. When `frozen=true`, the
  `useEffect` returns immediately (no rAF loop, no intervals). Removed the
  300ms `setInterval` for pixel-scale probing from both WaterCanvas and
  MetallicButton (ResizeObserver already handles size changes).

- **hero-33.tsx:** `{loadingComplete ? <WaterCanvas>{link}</WaterCanvas> : link}`
  → `<WaterCanvas frozen={!loadingComplete}>{link}</WaterCanvas>` — always
  renders WaterCanvas, water frozen until loader completes. No DOM restructure.

- **auth-context.tsx:** Wrapped `value` in `useMemo` — stable reference,
  no cascading re-renders.

- **elastic-line.tsx:** `useAnimationFrame` callback now returns early when
  `!isGrabbed && hasAnimatedIn` — only runs during initial animation or when
  the user is interacting.

- **hero-33.tsx:** `<img>` → `<Image priority>` (next/image). Removed the
  manual `<link rel="preload">` from layout.tsx (next/image handles it).

- **Ripple.tsx (page capture):** Removed `paintable.requestPaint()` from
  `syncCanvasSize()`. Added `captureContent()` function that explicitly
  captures on-demand. `splash()` calls `captureContent()`. Render loop
  requests a fresh capture before rendering active ripples.

## Deferred (2 findings — large rewrites)

- **#6 DotMatrix canvas rendering:** 1650-line component renders ~400 DOM
  elements for a 1.3s loading animation. Converting to canvas is a full
  rewrite. Impact is moderate (brief loader). Deferred.

- **#7 motion/react → CSS:** 5+ components import motion/react. Replacing
  simple animations with CSS is a multi-file rewrite. ElasticLine genuinely
  needs motion for physics. Deferred.

## Test results

- Frontend: **168/168** (`npm test`). Lint: 8 errors, 8 warnings (baseline:
  8 errors, 9 warnings — improved by 1 warning). Build: static, 6 routes.
- Added `next/image` mock to `hero-33.test.tsx` for the src attribute change.

## Known gaps / next step

- Browser verification of the double-load fix (content should appear once,
  smoothly, after the loader).
- #6 DotMatrix canvas rewrite (if loading animation performance matters).
- #7 motion→CSS (if bundle size is a concern).
- Docs updated.
