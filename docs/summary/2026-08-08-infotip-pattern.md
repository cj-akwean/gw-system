# 2026-08-08 — InfoTip pattern + frontend-design doc (Customer Portal §5)

## Goal

Complete ARCHITECTURE.md's unchecked item: "Tooltips: hover ⓘ on desktop, tap-to-toggle
popover on touch — one consistent pattern, seed the `frontend-design` doc" — the dangling
reference from `docs/prompts/payments-customer-portal-flow.md:80`.

## Files created

- `frontend/src/components/ui/info-tip.tsx` — shared InfoTip: single Radix `Popover`
  primitive (controlled `open`); hover path gated on `pointerType === "mouse"`
  (`pointerenter` opens after 200 ms, `pointerleave` closes after 120 ms grace;
  relatedTarget check lets the pointer travel trigger→content without flicker);
  touch taps never fire the hover path → Radix native tap-toggle, tap-outside and
  `Escape` close; `role="tooltip"` content in a `bg-popover` card with
  `aria-describedby` while open; `onOpen/CloseAutoFocus` preventDefault keeps focus on
  the trigger; empty/null content renders nothing; timers cleared on unmount;
  `openDelayMs`/`closeDelayMs` props (defaults 200/120) exposed for tests.
- `frontend/src/components/ui/info-tip.test.tsx` — 11 tests: desktop hover open/close,
  leave-before-delay never opens, trigger→content travel stays open, touch toggle,
  tap-outside close, Escape close, aria-describedby wiring, ReactNode content, empty
  content, unmount timer cleanup.
- `docs/insights/frontend-design.md` — seeded with the InfoTip pattern spec (behavior
  matrix per device, why pointer-type gating over matchMedia/device-swap, usage rules,
  testing constraint note).

## Files modified

- `frontend/src/components/portal/card-form.tsx` — CVC label gets ⓘ via a new
  `labelExtra` slot on the field helper (avoids a duplicate label).
- `frontend/src/components/portal/bill-card.tsx` + `payment-method.tsx` — Penalty rows
  get ⓘ ("2% per month interest on the unpaid balance, applied after the due date.").
- `ARCHITECTURE.md:242` — item checked, pointer to implementation-notes §5 + the doc.
- `docs/insights/implementation-notes.md` — Customer Portal §5.
- `docs/insights/product-decisions.md` — §36 (why one Popover primitive + pointer-type
  gating; the fake-timers finding).

## Bugs found & fixed (root cause, not symptom)

1. **vitest fake timers hang with React 19 + happy-dom.** Even `user.hover` on a plain
   button timed out. Root cause: happy-dom has **no `MessageChannel`** → React's
   scheduler falls back to `setTimeout` → `vi.useFakeTimers()` fakes it → `act` never
   flushes → every `await` hangs. Bisected with 4 scratch files. Fix: InfoTip exposes
   delay props; tests run real timers with small delays + generous waits.
   Recorded in frontend-design.md + product-decisions §36 (this is why the previous
   suites never hit it — they never used fake timers).
2. **Graphify stale manifest** (17,421 vendor entries from before `.graphifyignore`,
   ~2,135-node graph missing ~1,000 nodes of August additions). `--update` kept
   reporting "17,575 deleted" and blocked on missing LLM key for docs. Fix: regenerated
   the manifest baseline over the current ignored corpus (607 files), deleted the 5 new
   entries so they'd be re-extracted, ran `graphify update . --force` (code-only, no key
   needed) + `cluster-only` for the report. Graph now **3,149 nodes / 5,551 edges** (was
   2,135 / ~2,900), InfoTip + frontend-design doc present. 121 zero-node warnings are
   graphify CSV cache sidecars — harmless. Doc semantic nodes not refreshed (no LLM
   key); acceptable until a key is set.

## Test results

- Frontend: **118/118** (`npm test`; was 107, +11). `npm run lint`: no new issues
  (8 pre-existing errors in untouched files). `npm run build` + TypeScript: clean, all
  5 static routes prerendered. Backend untouched this session (no suite run needed).
- NOT yet verified manually: viewport pass (phone ~390 / tablet ~768 / desktop ~1280,
  light+dark) per AGENTS.md Rule 10 — needs a browser: hover delay + no flash on
  desktop, tap-toggle + outside-tap on a touch device, CVC ⓘ and both Penalty ⓘ.

## Known gaps / next step

- Hover-open/close delays are hand-rolled (deliberate tradeoff — see product-decisions
  §36), pinned by tests.
- Doc nodes (frontend-design.md etc.) are in the graph but semantic content stale until
  a GEMINI_API_KEY run.
- Next step (unchecked item): Card form is done → next portal item is "Save-card checkbox
  + vaulting" (deferred, same release as vaulting — not now) — so the next recommended
  item is the live test-mode payment round trip (pending from the 2026-08-08 payment
  session: 3DS + GCash + QR Ph with current ngrok), then a commit of both checklist
  items.

## Git state

NOT committed (per project rules).
