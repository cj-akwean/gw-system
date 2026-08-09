# 2026-08-09 — MCP browser verification pass (round 8)

## Goal

Per local AGENTS.md §5/§6: drive the real UI in Chrome via the chrome-devtools
MCP (now installed globally), verify the round-6/7 performance fixes actually
hold in a browser, and report verified-in-browser vs reasoned-only.

## What was verified in a real browser (webpack dev server, Edge via MCP)

| Check | Result |
|---|---|
| `/` renders | 200; brand, "Every Drop"/"Matters.", description, CTAs, water orb image all present |
| Loader→content transition | Loader mounts then unmounts; post-reload DOM shows ONE stable instance of every element (no duplicate content, no stuck overlay) — **double-load fix confirmed at DOM level** |
| Console on `/`, `/auth`, `/dashboard`, `/` (tablet) | **zero messages** on all pages |
| Ripple fallback path | Non-native: 1 canvas only (water button); `supportsHtmlInCanvas()` false in this Edge build (API not enabled by default) — fallback div path renders, no errors |
| Hamburger (390px, guest) | Opens: 4 nav items + Sign In (in water pill) + theme toggle; closes; no re-render artifacts |
| `/auth` | Login + Sign Up forms render (client-gated — matches earlier SSR check where forms were absent); "← Back to home" + floating theme toggle |
| **Login flow** | `test@example.com` / `password` → `POST /api/login` [200] → redirected to `/dashboard`; session persisted |
| `/dashboard` | Banner with brand→`/` link, ProfileDropdown, Link-your-meter prompt, My Bills ("no unpaid bills"), Past payments |
| ProfileDropdown | Settings · Dark mode · Sign Out (Dashboard correctly hidden on `/dashboard`) |
| Theme toggle | Click → `dark` class + `localStorage.theme=dark` persisted; no console errors |
| Network | login 200, `/api/links` + `/api/invoices` 200 (×2 each — LinkMeterPrompt + bills consumers; minor note, not a bug) |
| Responsive 768px (authenticated) | ProfileDropdown replaces Sign In (auth-aware nav correct at tablet) |

## Verified vs reasoned (local §6)

- **Verified in browser:** everything above (DOM, console, network, interaction, responsive).
- **Not verified:** visual appearance of screenshots — this model cannot view images
  (screenshots saved for human review: `%TEMP%\opencode\mcp-*.png`); LCP/INP
  numbers (no perf trace run — could be added to a future pass); the Ripple
  native path (needs a browser with `drawElementImage` enabled — Chrome flag).

## Environment notes

- Dev server: webpack (round 7 change), started/stopped by me, killed with
  `/T` — 0 node processes remain; user's 3 php processes untouched.
- Backend up (3 php processes), login + dashboard API calls all 200.

## Next step

LCP/perf trace pass (optional): `performance_start_trace` on `/` for real
Core Web Vitals numbers. Otherwise round 7 work is fully browser-verified.
