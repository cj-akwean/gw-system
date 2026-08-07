# 2026-08-07 — Portal-shell test trim + batch-verification rule

## Goal

Trim per-file frontend tests down to behavior-bearing suites, and codify in AGENTS.md
that tests run once near the end of implementation, not after every file edit.

## Files created or modified

- Deleted `frontend/src/components/portal/dashboard-header.test.tsx` and
  `frontend/src/components/portal/bill-card.test.tsx` (pure presentational suites;
  markup already exercised by `bills-list.test.tsx`, which renders real BillCards).
- `frontend/src/components/portal/bills-list.test.tsx` — folded bill-card's only unique
  branch in: new test asserts "Previous balance"/"Penalty" rows render when nonzero and
  are omitted on the zero fixture (18 → 13 tests).
- `frontend/package.json` + lockfile — removed unused `jsdom` devDep (config uses
  happy-dom; both had been installed).
- `AGENTS.md` — new Workflow Rule 9 "Batch verification — test once, near the end":
  full suite runs once after the checklist item is implemented, not per edit; exceptions
  are test-driven tasks and explicit user requests; unverified mid-session work is
  recorded in the session summary instead.
- `ARCHITECTURE.md` — portal-shell checkbox note corrected: `jsdom` → `happy-dom`,
  18 → 12 tests, dropped "header", noted presentational coverage via list tests.

## Bugs found and fixed

- None in app code. Docs drift fixed: ARCHITECTURE.md claimed jsdom + 18 tests; actual
  config is happy-dom and the old count included the two deleted suites.

## Test results

- Frontend `npm test`: 13 passed / 13 tests (dashboard guard ×3, bills list ×6,
  formatPeso ×4). Backend untouched this session — no phpunit run needed.

## Known gaps / next step

- `npm audit` reports 9 pre-existing vulnerabilities (3 moderate, 6 high) in the
  frontend dependency tree — untouched, unrelated to this change.
- Next portal checklist item (unchecked): Payment Method.

## Commit

- Not committed; no commit hash. Changes are staged as working-tree only.
