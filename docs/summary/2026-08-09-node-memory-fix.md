# 2026-08-09 — Node dev-server memory fix (webpack) + Chrome DevTools MCP setup (round 7)

## Goal

Fix the Next.js dev server reaching 3GB (and once 7GB) of RAM, and set up the
Chrome DevTools MCP globally per the updated local AGENTS.md §5.

## Root cause (verified, not guessed)

- **Turbopack dev-server memory leaks are a known, active Next.js bug class**
  (verified via GitHub vercel/next.js):
  - #95899 — "next dev renderer leaks ~5MB/s at idle (react-server-dom
    async_hooks promise tracking × Turbopack HMR churn) until V8 OOM"
  - #96857 — "turbo-tasks: explicit GC root anchoring + cross-session orphan
    reclamation" (still **Draft** — leak class unfixed upstream even in 16.3.0)
  - #96592 — "Turbopack: terminate failed plugin worker threads" (merged,
    lands in 16.3.0; we're on 16.2.12)
- **Amplifier:** our edit-heavy sessions = constant HMR churn; every save
  appends to Turbopack's dev graph → `.next` reached **872MB** (`.next/dev`
  662MB / 1,943 files, `dev/cache` 501MB). Turbopack maps this into RAM at
  startup → 3GB immediately, then the ~5MB/s leak → 7GB.
- Node v24.19.0, `output: "export"` (prod = static `out/`, 1.9MB), build
  already used webpack — only dev ran turbo.

## Changes

1. **`frontend/package.json`**: `"dev": "next dev --webpack"` — eliminates the
   leak class; consistent with the existing webpack build.
2. **Deleted `frontend/.next`** (872MB of bloated Turbopack cache).
3. **`frontend/next.config.ts`**: added `images: { unoptimized: true }` —
   found by the harness: `next/image` + `output: "export"` **throws 500 in
   dev** ("Image Optimization using the default loader is not compatible with
   `{ output: 'export' }`"). Static export has no optimizer API; the 129KB
   webp is already optimized, and `next/image` keeps width/height/priority
   benefits.
4. **Global opencode config** (`~/.config/opencode/opencode.jsonc`): added
   `chrome-devtools` MCP (`npx -y chrome-devtools-mcp@latest`). opencode
   hot-loaded it — MCP server processes are running (opencode-managed).
   **Tools are bound for the next session**, not this one.

## Memory measurement (webpack dev, harness in
`%TEMP%\opencode\mem-harness.ps1`)

| Phase | RSS |
|---|---|
| Startup (warm .next) | 208 MB |
| After page loads ×3 | ~890 MB |
| During HMR churn (5 file touches) | spikes to ~1.2 GB, GC'd back |
| Idle 60s (12 samples) | **flat 760 MB — zero growth** |

vs Turbopack before: 3GB at start, growing to 7GB. **≈4–9× reduction, no
leak.** (Note: 1.27GB transient during recompiles is normal webpack behavior;
it reclaimed to 760MB.)

## Browser verification (partial — see known gaps)

- Headless Edge screenshots: `/` mobile (390px) + desktop (1280px), `/auth`
  mobile — all produced non-blank renders (70–192KB).
- SSR DOM check (`Invoke-WebRequest`): `/` 200, hero content present
  (brand/title/water-orb/loader); Sign In + hamburger correctly gated behind
  the client-side auth `ready` flag. `/auth` 200, shell + "Back to home"
  present; form is client-gated.
- **Honesty:** this model cannot view images — screenshots not visually
  confirmed; console/network/perf/visual checks pending the MCP drive.
- New files: `%TEMP%\opencode\shot-*.png`, `mem-webpack.csv`,
  `dev-webpack.txt`.

## Known gaps / next step

1. **Next session (MCP drive, per local §5/§6):** `chrome-devtools` tools are
   live — navigate `/`, screenshot loader→content transition (double-load
   fix), `list_console_messages` on `/` `/auth` `/dashboard` (login
   test@example.com / password), responsive 390/768/1280, LCP re-measure.
2. Optional: update local AGENTS.md §5 wording — MCP is now **global**
   (`~/.config/opencode/opencode.jsonc`), not `.opencode/opencode.json`.
3. Optional future: revisit turbo only after Vercel ships the GC fix (#96857).

## Git state

NOT committed (per project rules).
