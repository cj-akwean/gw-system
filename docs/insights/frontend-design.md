# Frontend Design Decisions — Living Doc

Decisions about how the **customer-facing Next.js frontend** is designed and built —
shared UI patterns, component conventions, responsive rules. Seeded 2026-08-08 with the
InfoTip pattern (ARCHITECTURE.md → Customer Portal §5).

This is a *decisions* doc (the "why"), not an exhaustive component library. When a
pattern earns a repeat use, document it here; when it's used once, don't.

---

## 1. InfoTip — one consistent ⓘ tooltip/popover pattern

### The problem

Different parts of the UI explain jargon (Penalty, Security code, Arrears, e-wallet
limits). Before InfoTip there was no sanctioned way to attach a short explanation to a
label, and the pattern spec in `docs/prompts/payments-customer-portal-flow.md` demanded
one consistent approach: **hover ⓘ on desktop, tap-to-toggle popover on touch** — never
a ported hover tooltip.

### The decision

One shared component: `frontend/src/components/ui/info-tip.tsx`.

- **Trigger:** ⓘ (lucide `Info`), a real `<button>` with `aria-label` (default
  "More information").
- **Desktop (mouse):** hover opens after 200 ms (prevents flashes while moving across a
  form); leaving the trigger closes after a 120 ms grace (lets the pointer travel into
  the content without flicker). Clicking with a mouse also opens and stays open until
  the pointer leaves.
- **Touch (tap):** opens a popover; tap again, tap outside, or press `Escape` to close.
  Radix `Popover` handles all dismissal natively.
- **Keyboard:** the trigger is focusable; `Enter`/`Space` toggles, `Escape` closes.
- **Content:** anything ReactNode-worthy — short text, a list, even a link. Rendered in
  a `bg-popover` card, `role="tooltip"`, referenced from the trigger via
  `aria-describedby` while open.
- Empty/null content renders nothing at all (no orphan ⓘ).

### Why pointer-type gating instead of matchMedia / device-swapping

Two common approaches were rejected:

1. **CSS `:hover` / native `title`** — no delay control, no styling, invisible on touch.
2. **Swap Radix `Tooltip` on hover-capable devices and `Popover` on touch** — SSR can't
   read `matchMedia`, so the first client render must guess, and the post-mount swap
   remounts the trigger (focus loss + flash).

Instead, one Popover primitive with **`pointerType === "mouse"` gating** on
`pointerenter`/`pointerleave`: touch taps generate pointer events with
`pointerType: "touch"`, so the hover path simply never fires for them. No matchMedia,
no SSR branching, no hydration mismatch. Synthetic-mouse-event weirdness on hybrid
devices (touchscreen laptops) is impossible by construction — the gate is on the real
input device, not on event order.

### Rules for using it

- Use for a **short, contextual explanation** of a term in a label/row — Penalty, CVC,
  Arrears. One sentence at most.
- Do **not** use for critical information that must be visible (payment limits, errors)
  — that stays inline text (see the e-wallet ₱100k cap note).
- Do **not** create per-page tooltip variants. If a variant is genuinely needed (color,
  placement), extend InfoTip's props and document it here — it must stay one component.
- Text is copy, so keep it user-facing and plain ("2% per month interest on the unpaid
  balance, applied after the due date"), not system-speak.

### Tests

`info-tip.test.tsx` pins the whole behavior matrix: hover-open delay, leave-before-delay
never opens, trigger→content travel stays open, touch toggle, tap-outside close, Escape,
`aria-describedby` wiring, ReactNode content, empty content, timer cleanup on unmount.

> **Testing constraint (learned 2026-08-08):** vitest fake timers hang with React 19 +
> happy-dom — happy-dom has no `MessageChannel`, React's scheduler falls back to
> `setTimeout`, and faking it stalls `act` forever. InfoTip exposes `openDelayMs` /
> `closeDelayMs` (default 200/120) so tests use real timers with small delays and
> generous waits instead.
