# 2026-08-17 — Accounting & Finance module (replaces the Financial Report)

## Goal

User asked to refactor the "Financial Report" tab into a dedicated Accounting & Financial
Management module: AR aging analysis, cash-vs-accrual statement of income, a payment
breakdown/reconciliation ledger, range-bound PDF/Excel exports, and a "Total Receivables vs
Total Collections" summary card — while removing the dashboard-duplicating operational metrics.
Plan-mode Q&A settled: (1) keep Dashboard KPIs + revenue graph untouched; (2) reposition the
sidebar item below the resources (`navigationSort = 100`) rather than grouping it; (3) NOI =
(gross billed + misc) − cash collections; (4) miscellaneous income = stored penalty amounts only
(reconnection/setup fees not tracked → ₱0 + footnote).

## Files created / modified

| File | Action | What |
|---|---|---|
| `backend/app/Services/FinancialReportService.php` | rewritten | `build(?from, ?to)` → range (normalized, clamped), `summary` (receivables vs collections), `aging` (4 due-date buckets w/ stored penalties), `income` (gross billed / collections / misc / NOI); `normalizeRange()`, `agingBuckets()`, `incomeStatement()`, `ledgerRows()`; drops the old dashboard-metric dependency |
| `backend/app/Filament/Pages/FinancialReport.php` | rewritten | nav "Accounting & Finance", title "Accounting & Financial Management", `navigationSort = 100`, slug unchanged; `preset`/`from`/`to` `#[Url]` state + `updatedPreset`/`updatedFrom/To`; ledger `table(Table)` (method + paid_at filters) with `#[Url(as: 'filters')]` on `tableFilters`; exports now range-bound |
| `backend/resources/views/filament/pages/financial-report.blade.php` | rebuilt | range picker, receivables-vs-collections cards, aging table w/ totals, income statement + footnote, Payment Breakdown section rendering `{{ $this->table }}` |
| `backend/app/Exports/FinancialReportExport.php` | rewritten | 4 sheets: Summary, AR Aging, Income Statement, Payments Ledger; range-bound |
| `backend/app/Exports/FinancialReportSummarySheet.php` | rewritten | period + receivables + collections |
| `backend/app/Exports/FinancialReportAgingSheet.php` | new | aging bucket rows |
| `backend/app/Exports/FinancialReportIncomeSheet.php` | new | income lines + NOI |
| `backend/app/Exports/FinancialReportLedgerSheet.php` | new | FromQuery range-bounded ledger |
| `backend/app/Exports/FinancialReportRevenueSheet.php` | deleted | revenue-by-month sheet dropped (Dashboard owns the graph) |
| `backend/resources/views/pdfs/financial-report.blade.php` | rewritten | landscape, all four sections + full ledger |
| `backend/tests/Feature/FinancialReportTest.php` | rewritten | 6 → 11 tests |
| `ARCHITECTURE.md` | modified | Admin Reports §4 bullet |
| `docs/insights/implementation-notes.md` | modified | Admin Reports §4 rewritten |
| `docs/insights/product-decisions.md` | modified | new §49 |
| this summary | new | |

## Bugs found & fixed

1. **Filament v5: a plain `Page`'s table filters are not URL-bound.** The ledger's `method`
   filter silently did nothing when applied via the `filters` query string (test caught it:
   "assertCanNotSeeTableRecords" failed, both rows rendered). Root cause: `HasFilters` declares
   `tableFilters` as a plain property; only Resource list pages re-declare it with
   `#[Url(as: 'filters')]` (`ListRecords`). Fix: re-declare `#[Url(as: 'filters')] public ?array
   $tableFilters = null;` on the page. Without this, shared filter URLs would render unfiltered data.
2. **Carbon `diffInDays` sign.** `$today->diffInDays($dueDate, false)` returns *negative* for past
   dates (difference is `other − this`). First pass bucketed every invoice as "Current". Fix:
   `$invoice->due_date->diffInDays($today, false)` (positive = overdue days). Verified empirically.
3. **My own test bugs** (not code): asserted `range.label === 'monthly'` (label is the formatted
   date range); `assertSame('1', ...)` vs int `1` for the aging sheet count; the Excel test
   constructed the export without a range so the ledger showed all payments.

## Test results

- `--filter FinancialReportTest`: **11/11 green** (66 assertions).
- Broader batch `--filter "FinancialReport|Export|Dashboard|PaymentResource|AdminPanel|NotificationHub|Schedule"`:
  **143/143 green** (608 assertions).
- Smoke tests (real artifacts): `Excel::store` produced a valid `.xlsx` with sheets
  Summary / AR Aging / Income Statement / Payments Ledger; dompdf emitted a valid `%PDF-` file
  (26.7KB, landscape). `php artisan view:clear` run to drop stale compiled blades.
- Pre-commit sanity per AGENTS.md: `php -l` clean on all changed PHP files; no route changes.

## Known gaps / next step

- Reconnection / setup fees aren't tracked anywhere — income statement shows them as ₱0 with a
  "(not tracked)" footnote. A fees ledger (migration + resource) is the follow-up when the office
  needs it.
- Checks don't exist as a payment method in the data model — the ledger method filter offers
  Cash / Online (PayMongo) / Bank Transfer only.
- The ledger's filters are independent of the module's date range (by design); if the user wants
  the ledger to auto-follow the range, that's a small follow-up (seed `tableFilters['paid_at']`
  from the range).
- Manual/browser check not yet done: log in at `/admin` → sidebar should show "Accounting &
  Finance" below the resources; verify range picker + exports from the UI.
- Not committed (user hasn't asked). `graphify . --update` pending per AGENTS.md rule 3.

## Follow-up pass (same day) — UI polish

User: "fix the ui of the Accounting & Financial Management it is really ugly it like a bunch of text."

**Root cause found:** the backend runs Filament's PREBUILT CSS with **no Tailwind pipeline** — stock
Tailwind utilities like `.flex`, `.grid`, `.text-sm`, `.text-gray-500`, `.bg-gray-50`, `.rounded-lg`,
`.px-4`, `.space-y-6` **do not exist** in `public/css/filament/filament/app.css` (verified by grepping
the compiled CSS). The first version of the page was built from those classes, so it rendered as plain,
unstyled text. Filament's own component classes (`fi-section`, `fi-badge`, `fi-callout`, `fi-input`, ...)
and theme CSS variables (`--gray-*`, `--danger-600`, `--success-600`, `--warning-600`, `--primary-600`)
DO exist and are dark-mode aware.

**Fix:** rewrote `financial-report.blade.php` using only Filament Blade components + inline styles
referencing theme variables (no stock Tailwind utilities): section icons (`heroicon-o-*`) with
`icon-color`, stat cards (icon chip + label + big number + caption) for receivables/collections and the
3 income lines, color-coded aging badges + "share of receivables" progress bars + total footer, an
`x-filament::callout` for net operating income with the full formula in its description, and the range
preset/from/to controls with labeled inputs + an info badge for the selected period. The payment
reconciliation table (`$this->table`) was already properly styled.

Verified: page render test green; throwaway render dumped HTML — 57 SVGs, 16 badges, 4 aging progress
bars, callout, inputs all present. Test updated for the new heading casing. Broader batch 126/126 green.
Manual browser check still recommended (user hasn't confirmed the new look yet).

## Follow-up pass 2 (same day) — dark mode was broken

User: "make it consistent with the filament design also there is some white even when in dark mode the
colors are really messed up."

**Root cause:** Filament v5's theme CSS variables are **not remapped by dark mode**. `--gray-50`
through `--gray-950` always hold the light-palette values (`--gray-50` is oklch(0.985) ≈ near-white);
dark mode works via `:where(.dark, .dark *)` overrides + translucent-white (`color-mix(in oklab,
var(--color-white) 10%, transparent)`) surfaces on the dark body, and Filament toggles `dark` on
`<html>` (vendor `dark-mode.js`). So the first UI pass's `background:var(--gray-50)` cards,
`var(--gray-100)` borders/tracks and table strips stayed near-white in dark mode → "white in dark".

**Fix:** rewrote `financial-report.blade.php` again with a page-scoped `<style>` block that mirrors
Filament's own conventions: light values use `var(--color-white)` / `var(--gray-*)`; each custom
class (`.gws-card`, `.gws-thead`, `.gws-row`, `.gws-total`, `.gws-track`, `.gws-label`,
`.gws-caption`, `.gws-field-label`, `.gws-footnote`) gets an explicit `:where(.dark, .dark *)`
override using translucent white (`rgba(255,255,255,.04–.16)`), exactly like Filament's compiled CSS.
Chip/text accents switched to `--{color}-500` (readable on both light and dark). Everything else
(sections, badges, callout, inputs, the Filament reconciliation table) already handled dark mode via
their own classes.

Verified statically: rendered HTML contains the full scoped CSS with correct `:where(.dark, .dark *)`
overrides; `FinancialReportTest` 11/11 and the 126-test batch green. Not yet visually confirmed in a
browser — user asked to eyeball light + dark.

## Follow-up pass 3 (same day) — too much color on the metric icons

User: "there is really a lot of colors in this tab for icons there is green red yellow for Total
receivables, Total collections, Gross billed revenue, Cash collections, Miscellaneous income, ₱0.00
net operating income. i like the way filament does its icon minimal."

**Fix:** removed the colored icon chips (solid `--{color}-500` squares with white icons) from the five
metric cards. The cards now show a small, muted gray icon (`gws-icon`, `color:var(--gray-400)` /
`rgba(255,255,255,.45)` dark) next to the label + value — matching Filament's minimal stat look.
Also dropped `icon-color="primary"` from all five section headers (they now use Filament's default
gray) and made the period badge `color="gray"` instead of info-blue. Deliberately kept the only
remaining color: the aging buckets' severity badges (gray/warning/danger) + the aging progress-bar
fills, and the NOI callout's success/danger tint — all Filament-native, semantic, and small.

Verified: rendered HTML has 7 `gws-icon` usages, 0 `gws-chip`, 0 colored chip backgrounds, 0
`icon-color`; only the 3 aging severity bars carry color. `FinancialReportTest` 11/11, broader batch
126/126 green. Still pending the user's browser check.
